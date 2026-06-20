#!/usr/bin/env node
'use strict';

/*
 * OpenSEO review-reminder hook (PostToolUse).
 *
 * Claude Code hooks run shell commands and CANNOT invoke the Agent tool
 * directly. So instead of launching a reviewer, this hook injects a reminder
 * (via hookSpecificOutput.additionalContext) telling the main agent to launch
 * the matching reviewer at the right moment:
 *
 *   - a design spec under docs/superpowers/specs/  -> wp-design-reviewer
 *   - a plan under docs/superpowers/plans/         -> wp-plan-reviewer
 *   - a git commit that touches src/               -> wp-implementation-reviewer
 *
 * Reminders are intentionally non-forcing: the agent decides when the artifact
 * is actually complete, so iterative edits don't each spawn an expensive review.
 */

const fs = require('fs');
const { execSync } = require('child_process');

/** Read the hook payload from stdin (fd 0), tolerating an empty pipe. */
function readStdin() {
	try {
		return fs.readFileSync(0, 'utf8');
	} catch (err) {
		return '';
	}
}

/*
 * Emit an additionalContext reminder. We deliberately do NOT call
 * process.exit() here: when stdout is a pipe, exiting before the write drains
 * truncates the output. Returning lets main() unwind and Node flush naturally.
 */
function remind(context) {
	process.stdout.write(
		JSON.stringify({
			hookSpecificOutput: {
				hookEventName: 'PostToolUse',
				additionalContext: context,
			},
		})
	);
}

const DESIGN_MSG =
	'[review-reminder] A design spec under docs/superpowers/specs/ was just written or edited. ' +
	'When the spec is complete (not mid-edit), launch the wp-design-reviewer agent to audit it ' +
	'before an implementation plan is drafted.';

const PLAN_MSG =
	'[review-reminder] A plan under docs/superpowers/plans/ was just written or edited. ' +
	'When the plan is complete, launch the wp-plan-reviewer agent to audit it before execution.';

const IMPL_MSG =
	'[review-reminder] A git commit touching src/ just landed. If this commit completes a ' +
	'feature or task, launch the wp-implementation-reviewer agent to review the committed code.';

// Match whether the path is absolute or relative to the project root.
const SPEC_RE = /(?:^|\/)docs\/superpowers\/specs\/[^/]+\.md$/;
const PLAN_RE = /(?:^|\/)docs\/superpowers\/plans\/[^/]+\.md$/;

/** Forward slashes so one regex works on Windows and POSIX paths alike. */
function toPosix(p) {
	return String(p || '').replace(/\\/g, '/');
}

function main() {
	let payload;
	try {
		payload = JSON.parse(readStdin() || '{}');
	} catch (err) {
		return;
	}

	const tool = payload.tool_name || '';
	const input = payload.tool_input || {};

	if (tool === 'Write' || tool === 'Edit') {
		const filePath = toPosix(input.file_path);

		if (SPEC_RE.test(filePath)) {
			return remind(DESIGN_MSG);
		}
		if (PLAN_RE.test(filePath)) {
			return remind(PLAN_MSG);
		}
		return;
	}

	if (tool === 'Bash') {
		const command = String(input.command || '');

		if (!/\bgit\s+commit\b/.test(command)) {
			return;
		}

		// PostToolUse fires after the commit, so inspect the new HEAD's files.
		let changed = '';
		try {
			changed = execSync('git show --pretty=format: --name-only HEAD', {
				encoding: 'utf8',
			});
		} catch (err) {
			return;
		}

		const touchesSrc = changed
			.split(/\r?\n/)
			.some((file) => file.trim().startsWith('src/'));

		if (touchesSrc) {
			return remind(IMPL_MSG);
		}
	}
}

main();
