#!/usr/bin/env python3
"""Live check of local_paatshala 0.10.0 (completion settings, completion restrictions) on Paatshala.

Runs the PR's live plan through the ws_automation token and prints every raw JSON response.
It creates its own two pages and touches nothing else: no other activity, section or course.

Environment (as paatshala-mcp's .env; never committed):
    PAATSHALA_BASE_URL      e.g. https://paatshala.example.org
    PAATSHALA_WRITE_TOKEN   the ws_automation token holding the plugin's functions

Steps:
    run --section-id 2985 --group GROUPID
        In course 237 (or --course), whose section with that id must be hidden: create two
        hidden pages A and B (create_activity); get_completion on both; set_completion
        none -> manual on both (expected from get_completion's state; a page created with the
        site's default completion is first set to none); set_access on B
        (completion of A, hidden entirely; and the group, hidden entirely); get_availability
        on B; get_completion on A (referencedby must list B); journal_list; undo the
        restriction, then both completion changes (newest first); check every value is back.
        Prints the cmids of A and B at the end for cleanup.
    cleanup --cmid CMID [--cmid CMID ...]
        local_paatshala_delete_created on each (to the course recycle bin). An error on one
        (or one outside the course, or visible) is printed and the next is tried; the exit
        status is non-zero if any was not deleted.

Nothing is done in courses 450 or 452. run exits non-zero if any check fails (after undoing
what it did). Python 3.6+, standard library only.
"""

import argparse
import json
import os
import sys
import time
import urllib.parse
import urllib.request

COURSE = 237
NEVER = (450, 452)


class MoodleError(Exception):
    """A Moodle exception answer (HTTP 200 with exception/errorcode/message)."""

    def __init__(self, answer):
        super().__init__('{}: {}'.format(answer.get('errorcode'), answer.get('message')))
        self.answer = answer
        self.errorcode = answer.get('errorcode')


def flatten(params, prefix=''):
    """PHP-style flattening: completion[conditions][0][cmid]=12, expected=<json string>."""
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
    print('\n=== {} {}'.format(function, json.dumps(params, ensure_ascii=False)))
    print(json.dumps(answer, indent=2, ensure_ascii=False))
    if isinstance(answer, dict) and 'exception' in answer:
        raise MoodleError(answer)
    return answer


class TargetError(Exception):
    """An activity the script may not touch."""


def check_target(cmid, course):
    """Raise TargetError unless the activity is in the course and hidden."""
    settings = call('local_paatshala_get_module_settings', cmid=cmid)
    if settings['courseid'] != course or settings['visible'] != 0:
        raise TargetError('cm {} is in course {} and visible={}; only hidden activities of course {} are used'.format(
            cmid, settings['courseid'], settings['visible'], course))
    return settings


def step_run(args):
    if args.course in NEVER:
        sys.exit('courses 450 and 452 have students; use the test course')
    since = int(time.time()) - 60
    stamp = time.strftime('%Y-%m-%d %H:%M:%S')
    section = call('local_paatshala_get_availability', courseid=args.course, sectionid=args.section_id)
    if section['visible'] != 0:
        sys.exit('section {} is visible; use a hidden section'.format(args.section_id))
    if section['availability']:
        sys.exit('section {} has access restrictions; use one without'.format(args.section_id))

    cmids = []
    for name in ('A', 'B'):
        result = call('local_paatshala_create_activity', courseid=args.course, sectionnum=section['sectionnum'],
                      modname='page', fields=json.dumps({
                          'name': 'Completion check {} {}'.format(name, stamp),
                          'intro': 'local_paatshala live check of completion settings ({})'.format(stamp),
                          'content': '<p>Live check page {}; delete after the check.</p>'.format(name),
                          'visible': 0}))
        cmids.append(json.loads(result['after'])['cmid'])
    a, b = cmids
    print('--- created: A = cm {}, B = cm {}'.format(a, b))
    for cmid in cmids:
        check_target(cmid, args.course)

    initial = [call('local_paatshala_get_completion', cmid=cmid)['completion'] for cmid in cmids]
    problems = []
    entries = []
    try:
        for cmid in cmids:
            read = call('local_paatshala_get_completion', cmid=cmid)
            if not read['siteenabled'] or not read['courseenabled']:
                problems.append('cm {}: siteenabled {} courseenabled {}'.format(
                    cmid, read['siteenabled'], read['courseenabled']))
            if read['completion'] != 0:
                # New activities get the site's default completion ($CFG->completiondefault), as on the
                # settings page: start from none.
                result = call('local_paatshala_set_completion', cmid=cmid, fields=json.dumps({'completion': 'none'}),
                              expected=read['state'])
                entries.append(result['journalid'])
                read = call('local_paatshala_get_completion', cmid=cmid)
            result = call('local_paatshala_set_completion', cmid=cmid, fields=json.dumps({'completion': 'manual'}),
                          expected=read['state'])
            entries.append(result['journalid'])
            if call('local_paatshala_get_completion', cmid=cmid)['completion'] != 1:
                problems.append('cm {}: completion is not manual after set_completion'.format(cmid))

        access = call('local_paatshala_set_access', courseid=args.course, cmid=b, expected=json.dumps(
            {'availability': ''}), completion={'action': 'set', 'conditions': [{'cmid': a, 'state': 'complete'}],
                                               'whenunmet': 'hide'},
            group={'action': 'set', 'groupids': [args.group], 'whenunmet': 'hide'})
        entries.append(access['journalid'])
        restricted = call('local_paatshala_get_availability', courseid=args.course, cmid=b)
        types = [condition['type'] for condition in restricted['conditions']]
        if types != ['group', 'completion']:
            problems.append('B has conditions {}, expected group and completion'.format(types))
        names = [condition.get('cm', {}).get('cmid') for condition in restricted['conditions']]
        if a not in names:
            problems.append('B\'s completion condition does not name A')
        referencedby = [item['cmid'] for item in call('local_paatshala_get_completion', cmid=a)['referencedby']]
        if referencedby != [b]:
            problems.append('A referencedby is {}, expected [{}]'.format(referencedby, b))
        # Completion off on A while B points at it: refused.
        try:
            call('local_paatshala_set_completion', cmid=a, fields=json.dumps({'completion': 'none'}))
            problems.append('set_completion none on A was not refused')
        except MoodleError as error:
            if error.errorcode != 'referenced':
                problems.append('set_completion none on A: {} instead of referenced'.format(error.errorcode))
        call('local_paatshala_journal_list', courseid=args.course, since=since, limit=0)
    finally:
        # Undo newest first: the restriction, then the completion changes.
        for journalid in reversed([entry for entry in entries if entry]):
            try:
                call('local_paatshala_undo', journalid=journalid)
            except MoodleError as error:
                problems.append('undo of journal entry {}: {}'.format(journalid, error))

    if call('local_paatshala_get_availability', courseid=args.course, cmid=b)['availability'] != '':
        problems.append('B still has a restriction after undo')
    for cmid, created in zip(cmids, initial):
        if call('local_paatshala_get_completion', cmid=cmid)['completion'] != created:
            problems.append('cm {}: completion is not back to {} after undo'.format(cmid, created))
    print('\n--- pages: A = cm {}, B = cm {}; clean up with: cleanup --cmid {} --cmid {}'.format(a, b, a, b))
    print('--- {}'.format('OK' if not problems else 'PROBLEMS: ' + '; '.join(problems)))
    if problems:
        sys.exit(1)


def step_cleanup(args):
    failed = []
    for cmid in args.cmid:
        try:
            check_target(cmid, args.course)
            call('local_paatshala_delete_created', cmid=cmid)
        except (MoodleError, TargetError) as error:
            print('--- cm {}: not deleted: {}'.format(cmid, error))
            failed.append(cmid)
    if failed:
        sys.exit('not deleted: cm ' + ', '.join(str(cmid) for cmid in failed))


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--course', type=int, default=COURSE, help='course id (default 237)')
    steps = parser.add_subparsers(dest='step')
    run = steps.add_parser('run', help='create two pages, completion and a completion restriction, undo')
    run.add_argument('--section-id', type=int, required=True, help='a hidden section of the course (2985)')
    run.add_argument('--group', type=int, required=True, help='a group of the course for the group part')
    cleanup = steps.add_parser('cleanup', help='delete the test pages to the recycle bin')
    cleanup.add_argument('--cmid', type=int, action='append', required=True)
    args = parser.parse_args()
    if args.step is None:
        parser.error('give a step: run or cleanup')
    if args.course in NEVER:
        parser.error('courses 450 and 452 have students; use the test course')
    {'run': step_run, 'cleanup': step_cleanup}[args.step](args)


if __name__ == '__main__':
    main()
