#!/usr/bin/env python3
"""Live check of local_paatshala 0.9.0 (activity texts and the file guard) on Paatshala.

Runs the PR's live plan through the ws_automation token, on activities given by cmid only,
and prints every raw JSON response. Nothing outside the given cmids is read or changed.

Environment (as paatshala-mcp's .env; never committed):
    PAATSHALA_BASE_URL      e.g. https://paatshala.example.org
    PAATSHALA_WRITE_TOKEN   the ws_automation token holding the plugin's functions

Steps (run in this order; see the PR for the manual steps between them):
    edit  --page CMID --label CMID
        get_content, update_content on the page's content and intro and on the label's
        intro, undo and redo each, the label's name after every step, journal_list.
    guard --page CMID
        On a page whose content holds a teacher's image larger than the site's maximum
        upload size: get_content (atrisk must list it), update_content and set_access
        (both must answer filesaltered), get_content again (the image still there).
        Refuses to write while atrisk is empty, so nothing is changed by mistake.
    cleanup --cmid CMID [--cmid CMID ...]
        local_paatshala_delete_created on each (to the course recycle bin).

Every cmid must be in course 237 (or --course) and hidden, or the script stops before
any write. Python 3.6+, standard library only.
"""

import argparse
import json
import os
import sys
import time
import urllib.parse
import urllib.request

COURSE = 237
MARK = '<p>[local_paatshala live check {}]</p>'


class MoodleError(Exception):
    """A Moodle exception answer (HTTP 200 with exception/errorcode/message)."""

    def __init__(self, answer):
        super().__init__(answer.get('errorcode'))
        self.answer = answer
        self.errorcode = answer.get('errorcode')


def flatten(params, prefix=''):
    """PHP-style flattening: from[action]=set, expected=<json string>."""
    flat = []
    for key, value in params.items():
        name = '{}[{}]'.format(prefix, key) if prefix else key
        if isinstance(value, dict):
            flat.extend(flatten(value, name))
        elif isinstance(value, (list, tuple)):
            for i, item in enumerate(value):
                if isinstance(item, dict):
                    flat.extend(flatten(item, '{}[{}]'.format(name, i)))
                else:
                    flat.append(('{}[{}]'.format(name, i), str(item)))
        elif isinstance(value, bool):
            flat.append((name, '1' if value else '0'))
        elif value is not None:
            flat.append((name, str(value)))
    return flat


def call(function, **params):
    """Call a web service function, print the raw answer, return it (raise on an exception)."""
    base = os.environ.get('PAATSHALA_BASE_URL', '').rstrip('/')
    token = os.environ.get('PAATSHALA_WRITE_TOKEN', '')
    if not base or not token:
        sys.exit('Set PAATSHALA_BASE_URL and PAATSHALA_WRITE_TOKEN')
    data = [('wstoken', token), ('wsfunction', function), ('moodlewsrestformat', 'json')] + flatten(params)
    request = urllib.request.Request(base + '/webservice/rest/server.php',
                                     data=urllib.parse.urlencode(data).encode())
    with urllib.request.urlopen(request, timeout=120) as response:
        answer = json.loads(response.read().decode('utf-8'))
    shown = {k: v for k, v in params.items()}
    print('\n=== {} {}'.format(function, json.dumps(shown, ensure_ascii=False)))
    print(json.dumps(answer, indent=2, ensure_ascii=False))
    if isinstance(answer, dict) and 'exception' in answer:
        raise MoodleError(answer)
    return answer


def require_target(cmid, course):
    """Stop unless the activity is in the course and hidden."""
    settings = call('local_paatshala_get_module_settings', cmid=cmid)
    if settings['courseid'] != course or settings['visible'] != 0:
        sys.exit('cm {} is in course {} and visible={}; only hidden activities of course {} are used'.format(
            cmid, settings['courseid'], settings['visible'], course))
    return settings


def field(content, name):
    """One field of a get_content answer."""
    for item in content['fields']:
        if item['field'] == name:
            return item
    sys.exit('cm {} has no field {}'.format(content['cmid'], name))


def expected_of(item):
    """The expected JSON for update_content: the field as get_content returned it."""
    return json.dumps({'text': item['text'], 'format': item['format'], 'files': item['files']})


def label_name(cmid):
    """The label's name, as the course page shows it."""
    name = call('local_paatshala_get_module_settings', cmid=cmid)['name']
    print('--- label name now: {!r}'.format(name))
    return name


def step_edit(args):
    since = int(time.time()) - 60
    stamp = time.strftime('%Y-%m-%d %H:%M:%S')
    for cmid in (args.page, args.label):
        require_target(cmid, args.course)

    entries = []
    label_name(args.label)
    for cmid, name in ((args.page, 'content'), (args.page, 'intro'), (args.label, 'intro')):
        before = field(call('local_paatshala_get_content', cmid=cmid), name)
        result = call('local_paatshala_update_content', cmid=cmid, field=name,
                      text=before['text'] + MARK.format(stamp), format=before['format'],
                      expected=expected_of(before))
        after = field(call('local_paatshala_get_content', cmid=cmid), name)
        print('--- {} {}: files kept: {}, missing references: {}'.format(
            cmid, name, after['files'] == before['files'], after['missing']))
        if cmid == args.label:
            label_name(args.label)
        entries.append((cmid, name, result['journalid']))

    for cmid, name, journalid in entries:
        undone = call('local_paatshala_undo', journalid=journalid)
        if cmid == args.label:
            label_name(args.label)
        call('local_paatshala_undo', journalid=undone['journalid'])
        if cmid == args.label:
            label_name(args.label)

    call('local_paatshala_journal_list', courseid=args.course, since=since, kind='module_content', limit=0)


def step_guard(args):
    require_target(args.page, args.course)
    content = call('local_paatshala_get_content', cmid=args.page)
    if not content['atrisk']:
        sys.exit('atrisk is empty: lower the site maximum upload size below the image size first')

    def expect_filesaltered(function, **params):
        try:
            result = call(function, **params)
        except MoodleError as error:
            print('--- {}: {}'.format(function, 'OK, filesaltered' if error.errorcode == 'filesaltered'
                                      else 'UNEXPECTED errorcode ' + str(error.errorcode)))
            return
        print('--- {}: UNEXPECTED success; undoing journal entry {}'.format(function, result['journalid']))
        if result['journalid']:
            call('local_paatshala_undo', journalid=result['journalid'])

    intro = field(content, 'intro')
    expect_filesaltered('local_paatshala_update_content', cmid=args.page, field='intro',
                        text=intro['text'] + MARK.format('guard'), format=intro['format'],
                        expected=expected_of(intro))
    expect_filesaltered('local_paatshala_set_access', courseid=args.course, cmid=args.page,
                        **{'from': {'action': 'set', 'time': int(time.time()) + 86400 * 365, 'whenunmet': 'show'}})

    again = call('local_paatshala_get_content', cmid=args.page)
    before = {(f['filepath'], f['filename'], f['contenthash']) for f in field(content, 'content')['files']}
    now = {(f['filepath'], f['filename'], f['contenthash']) for f in field(again, 'content')['files']}
    print('--- content files unchanged: {}; still at risk: {}'.format(before == now, again['atrisk']))


def step_cleanup(args):
    for cmid in args.cmid:
        require_target(cmid, args.course)
    for cmid in args.cmid:
        call('local_paatshala_delete_created', cmid=cmid)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--course', type=int, default=COURSE, help='course id (default 237)')
    steps = parser.add_subparsers(dest='step')
    edit = steps.add_parser('edit', help='edit, undo and redo a page and a label')
    edit.add_argument('--page', type=int, required=True)
    edit.add_argument('--label', type=int, required=True)
    guard = steps.add_parser('guard', help='the drop guard on a page with an oversized image')
    guard.add_argument('--page', type=int, required=True)
    cleanup = steps.add_parser('cleanup', help='delete the test activities to the recycle bin')
    cleanup.add_argument('--cmid', type=int, action='append', required=True)
    args = parser.parse_args()
    if args.step is None:
        parser.error('give a step: edit, guard or cleanup')
    {'edit': step_edit, 'guard': step_guard, 'cleanup': step_cleanup}[args.step](args)


if __name__ == '__main__':
    main()
