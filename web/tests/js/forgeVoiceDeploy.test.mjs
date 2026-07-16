import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmod, cp, mkdir, mkdtemp, readFile, realpath, rm, symlink, unlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const deployScript = join(repositoryRoot, 'scripts/forge-deploy.sh');
const forgeRecipe = join(repositoryRoot, 'scripts/forge-zero-downtime-deploy.template');

async function makeExecutable(path, source) {
    await writeFile(path, source, 'utf8');
    await chmod(path, 0o755);
}

async function createFixture() {
    const root = await mkdtemp(join(tmpdir(), 'bean-forge-deploy-'));
    const siteRoot = join(root, 'site');
    const releasesRoot = join(siteRoot, 'releases');
    const current = join(siteRoot, 'current');
    const runtime = join(siteRoot, '.bean-voice-runtime');
    const fakeState = join(root, 'fake-state');
    const bin = join(root, 'bin');
    const calls = join(fakeState, 'calls.log');
    const releases = {};

    await mkdir(releasesRoot, { recursive: true });
    await mkdir(fakeState, { recursive: true });
    await mkdir(bin, { recursive: true });

    for (const name of ['release-1', 'release-2']) {
        const release = join(releasesRoot, name);
        releases[name] = release;
        await mkdir(join(release, 'scripts'), { recursive: true });
        await mkdir(join(release, 'web/bootstrap/cache'), { recursive: true });
        await cp(deployScript, join(release, 'scripts/forge-deploy.sh'));
        await writeFile(join(release, 'web/artisan'), '# fixture artisan\n', 'utf8');
        await chmod(join(release, 'web/artisan'), 0o755);
    }
    await symlink(releases['release-1'], current);

    const php = join(bin, 'php');
    const composer = join(bin, 'composer');
    const npm = join(bin, 'npm');
    const inspector = join(bin, 'inspect-process');

    await makeExecutable(php, `#!/usr/bin/env bash
set -eu
printf '%s\\n' "$*" >> "$FAKE_CALLS"
sideband_pids="$FAKE_STATE/sideband.pids"
worker_pids="$FAKE_STATE/worker.pids"

stop_recorded() {
    file="$1"
    if [ -f "$file" ]; then
        while IFS= read -r pid; do
            case "$pid" in ''|*[!0-9]*) continue ;; esac
            kill -TERM "$pid" 2>/dev/null || true
        done < "$file"
    fi
    : > "$file"
}

case "$*" in
    *"voice:realtime-sidebands-restart"*)
        [ "\${FAKE_IGNORE_SIDEBAND_RESTART:-0}" = 1 ] || stop_recorded "$sideband_pids"
        exit 0
        ;;
    *"queue:restart"*) stop_recorded "$worker_pids"; exit 0 ;;
    *"voice:realtime-sidebands "*)
        [ "\${FAKE_FAIL_SIDEBAND:-0}" = 0 ] || exit 70
        printf '%s\\n' "$$" >> "$sideband_pids"
        trap 'exit 0' TERM INT
        while :; do sleep 1; done
        ;;
    *"queue:work "*"--queue=voice-high"*)
        [ "\${FAKE_FAIL_WORKERS:-0}" = 0 ] || exit 71
        printf '%s\\n' "$$" >> "$worker_pids"
        trap 'exit 0' TERM INT
        while :; do sleep 1; done
        ;;
    *) exit 0 ;;
esac
`);

    await makeExecutable(composer, `#!/usr/bin/env bash
printf 'composer %s\\n' "$*" >> "$FAKE_CALLS"
`);
    await makeExecutable(npm, `#!/usr/bin/env bash
printf 'npm %s\\n' "$*" >> "$FAKE_CALLS"
`);
    await makeExecutable(inspector, `#!/usr/bin/env bash
set -eu
operation="$1"
shift
case "$operation" in
    alive) kill -0 "$1" 2>/dev/null ;;
    cwd)
        if [ -e "/proc/$1/cwd" ]; then
            readlink -f "/proc/$1/cwd"
        else
            lsof -a -p "$1" -d cwd -Fn | sed -n 's/^n//p'
        fi
        ;;
    command) ps -p "$1" -o command= ;;
    list)
        role="$1"
        ps -axo pid=,command= | while read -r pid command_line; do
            case "$command_line" in *"$TEST_SITE_ROOT"*) ;; *) continue ;; esac
            case "$role:$command_line" in
                sideband:*"artisan voice:realtime-sidebands "*) printf '%s\\n' "$pid" ;;
                worker:*"artisan queue:work "*"--queue=voice-high"*) printf '%s\\n' "$pid" ;;
            esac
        done
        ;;
    *) exit 64 ;;
esac
`);

    const baseEnv = {
        ...process.env,
        PATH: `${bin}:${process.env.PATH}`,
        FORGE_SITE_PATH: current,
        FORGE_SITE_ROOT: siteRoot,
        BEAN_VOICE_RUNTIME_ROOT: runtime,
        BEAN_VOICE_PROCESS_INSPECTOR: inspector,
        BEAN_VOICE_STARTUP_TIMEOUT_SECONDS: '2',
        BEAN_VOICE_HANDOFF_TIMEOUT_SECONDS: '2',
        BEAN_VOICE_HEALTH_INTERVAL_SECONDS: '0.05',
        FORGE_PHP: php,
        FORGE_COMPOSER: composer,
        FAKE_CALLS: calls,
        FAKE_STATE: fakeState,
        TEST_SITE_ROOT: siteRoot,
    };

    function run(command, releaseName = 'release-1', extraEnv = {}) {
        return spawnSync('bash', [join(current, 'scripts/forge-deploy.sh'), command], {
            cwd: current,
            encoding: 'utf8',
            env: {
                ...baseEnv,
                FORGE_RELEASE_DIRECTORY: releases[releaseName],
                ...extraEnv,
            },
            timeout: 15_000,
        });
    }

    async function activateLink(releaseName) {
        await unlink(current);
        await symlink(releases[releaseName], current);
    }

    async function cleanup() {
        for (const file of [join(fakeState, 'sideband.pids'), join(fakeState, 'worker.pids')]) {
            try {
                for (const value of (await readFile(file, 'utf8')).trim().split(/\s+/)) {
                    if (/^\d+$/.test(value)) {
                        try {
                            process.kill(Number(value), 'SIGTERM');
                        } catch {}
                    }
                }
            } catch {}
        }
        await new Promise((resolvePromise) => setTimeout(resolvePromise, 50));
        await rm(root, { recursive: true, force: true });
    }

    return {
        activateLink,
        baseEnv,
        calls,
        cleanup,
        current,
        releases,
        root,
        run,
        runtime,
        siteRoot,
    };
}

test('[BV2-DEPLOY-03] Forge activation is post-release, current-generation, and idempotent', async (t) => {
    const fixture = await createFixture();
    t.after(fixture.cleanup);

    const first = fixture.run('activate');
    assert.equal(first.status, 0, first.stderr || first.stdout);

    const state = join(fixture.runtime, 'state');
    const firstSideband = (await readFile(join(state, 'sideband.pid'), 'utf8')).trim();
    const firstWorkers = (await readFile(join(state, 'workers.pid'), 'utf8')).trim().split(/\s+/);
    const firstCalls = await readFile(fixture.calls, 'utf8');
    const releaseOne = await realpath(fixture.releases['release-1']);
    const escapedReleaseOne = releaseOne.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    assert.match(firstCalls, /voice:realtime-sidebands-restart/);
    assert.match(firstCalls, /queue:restart/);
    assert.match(firstCalls, new RegExp(`${escapedReleaseOne}/web/artisan voice:realtime-sidebands `));
    assert.match(firstCalls, new RegExp(`${escapedReleaseOne}/web/artisan queue:work `));
    assert.equal(firstWorkers.length, 3);
    assert.equal((await readFile(join(state, 'release'), 'utf8')).trim(), releaseOne);

    const inspectedCwd = spawnSync(fixture.baseEnv.BEAN_VOICE_PROCESS_INSPECTOR, ['cwd', firstSideband], {
        encoding: 'utf8',
        env: fixture.baseEnv,
    });
    assert.equal(inspectedCwd.status, 0, inspectedCwd.stderr);
    assert.equal(inspectedCwd.stdout.trim(), await realpath(join(fixture.releases['release-1'], 'web')));

    const repeat = fixture.run('activate');
    assert.equal(repeat.status, 0, repeat.stderr || repeat.stdout);
    assert.match(repeat.stdout, /already healthy/);
    assert.equal((await readFile(join(state, 'sideband.pid'), 'utf8')).trim(), firstSideband);
    assert.deepEqual((await readFile(join(state, 'workers.pid'), 'utf8')).trim().split(/\s+/), firstWorkers);
    assert.equal(await readFile(fixture.calls, 'utf8'), firstCalls, 'idempotent activation must not restart a healthy generation');

    const healthy = fixture.run('status');
    assert.equal(healthy.status, 0, healthy.stderr || healthy.stdout);
    assert.match(healthy.stdout, /voice runtime healthy/);
});

test('[BV2-SIDEBAND-01] a zero-downtime release handoff replaces stale sideband and voice-high generations', async (t) => {
    const fixture = await createFixture();
    t.after(fixture.cleanup);

    const first = fixture.run('activate');
    assert.equal(first.status, 0, first.stderr || first.stdout);
    const state = join(fixture.runtime, 'state');
    const oldSideband = (await readFile(join(state, 'sideband.pid'), 'utf8')).trim();
    const oldWorkers = (await readFile(join(state, 'workers.pid'), 'utf8')).trim().split(/\s+/);

    await fixture.activateLink('release-2');

    const stale = fixture.run('status', 'release-2');
    assert.notEqual(stale.status, 0);
    assert.match(stale.stderr, /missing, stale, or not running/);

    const handoff = fixture.run('activate', 'release-2', {
        BEAN_VOICE_HANDOFF_TIMEOUT_SECONDS: '0',
        BEAN_VOICE_TERM_GRACE_TIMEOUT_SECONDS: '2',
        FAKE_IGNORE_SIDEBAND_RESTART: '1',
    });
    assert.equal(handoff.status, 0, handoff.stderr || handoff.stdout);
    const newSideband = (await readFile(join(state, 'sideband.pid'), 'utf8')).trim();
    const newWorkers = (await readFile(join(state, 'workers.pid'), 'utf8')).trim().split(/\s+/);
    assert.notEqual(newSideband, oldSideband);
    assert.equal(newWorkers.length, 3);
    assert.deepEqual(newWorkers.filter((pid) => oldWorkers.includes(pid)), []);
    assert.equal((await readFile(join(state, 'release'), 'utf8')).trim(), await realpath(fixture.releases['release-2']));
    const retired = spawnSync(fixture.baseEnv.BEAN_VOICE_PROCESS_INSPECTOR, ['alive', oldSideband], {
        encoding: 'utf8',
        env: fixture.baseEnv,
    });
    assert.notEqual(retired.status, 0, 'bounded TERM handoff must retire a stale sideband that ignored restart');

    const healthy = fixture.run('status', 'release-2');
    assert.equal(healthy.status, 0, healthy.stderr || healthy.stdout);
});

test('[BV2-DEPLOY-08] manual SSH activation infers stable site state without Forge environment variables', async (t) => {
    const fixture = await createFixture();
    t.after(fixture.cleanup);

    const env = { ...fixture.baseEnv };
    for (const key of [
        'FORGE_SITE_PATH',
        'FORGE_SITE_ROOT',
        'FORGE_RELEASE_DIRECTORY',
        'FORGE_PHP',
        'FORGE_COMPOSER',
        'BEAN_VOICE_RUNTIME_ROOT',
    ]) delete env[key];

    const activate = spawnSync('bash', [join(fixture.current, 'scripts/forge-deploy.sh'), 'activate'], {
        cwd: fixture.current,
        encoding: 'utf8',
        env,
        timeout: 15_000,
    });
    assert.equal(activate.status, 0, activate.stderr || activate.stdout);

    const stableState = join(fixture.siteRoot, '.bean-voice-runtime/state');
    assert.equal(
        (await readFile(join(stableState, 'release'), 'utf8')).trim(),
        await realpath(fixture.releases['release-1']),
    );
    await assert.rejects(readFile(join(fixture.siteRoot, 'releases/.bean-voice-runtime/state/release'), 'utf8'));

    const status = spawnSync('bash', [join(fixture.current, 'scripts/forge-deploy.sh'), 'status'], {
        cwd: fixture.current,
        encoding: 'utf8',
        env,
        timeout: 5_000,
    });
    assert.equal(status.status, 0, status.stderr || status.stdout);
});

test('[BV2-DEPLOY-04] activation fails closed before Forge points current at the candidate release', async (t) => {
    const fixture = await createFixture();
    t.after(fixture.cleanup);

    const candidateScript = join(fixture.releases['release-2'], 'scripts/forge-deploy.sh');
    const result = spawnSync('bash', [candidateScript, 'activate'], {
        cwd: fixture.releases['release-2'],
        encoding: 'utf8',
        env: {
            ...fixture.baseEnv,
            FORGE_RELEASE_DIRECTORY: fixture.releases['release-2'],
        },
        timeout: 5_000,
    });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /activate must run after \$ACTIVATE_RELEASE/);
});

test('[BV2-DEPLOY-05] a missing voice-high generation blocks activation instead of publishing false readiness', async (t) => {
    const fixture = await createFixture();
    t.after(fixture.cleanup);

    const failed = fixture.run('activate', 'release-1', { FAKE_FAIL_WORKERS: '1' });
    assert.notEqual(failed.status, 0);
    assert.match(failed.stderr, /worker exited or started from the wrong release/);
    await assert.rejects(readFile(join(fixture.runtime, 'state/release'), 'utf8'));
});

test('[BV2-DEPLOY-06] the Forge recipe activates before replacing and verifying voice processes', async () => {
    const recipe = await readFile(forgeRecipe, 'utf8');
    const script = await readFile(deployScript, 'utf8');

    const create = recipe.indexOf('$CREATE_RELEASE()');
    const prepare = recipe.indexOf('forge-deploy.sh prepare');
    const activate = recipe.indexOf('$ACTIVATE_RELEASE()');
    const restartQueues = recipe.indexOf('$RESTART_QUEUES()');
    const activateVoice = recipe.indexOf('forge-deploy.sh activate');
    const publicPreflight = recipe.indexOf('preflight:voice:production');
    const processPreflight = recipe.indexOf('forge-deploy.sh status');

    assert.ok(create < prepare && prepare < activate);
    assert.ok(activate < restartQueues && restartQueues < activateVoice);
    assert.ok(activateVoice < publicPreflight && publicPreflight < processPreflight);
    assert.doesNotMatch(script, /\/home\/forge\/heybean\.org\/current/);
    assert.doesNotMatch(script, /git (?:checkout|reset)/);
    assert.match(script, /VOICE_WORKER_COUNT=3/);
    assert.match(script, /TERM_GRACE_TIMEOUT="\$\{BEAN_VOICE_TERM_GRACE_TIMEOUT_SECONDS:-5\}"/);
    assert.match(script, /--queue=voice-high/);
});

test('[BV2-DEPLOY-07] release preparation operates on Forge release context, not the old current symlink', async (t) => {
    const fixture = await createFixture();
    t.after(fixture.cleanup);

    const candidateScript = join(fixture.releases['release-2'], 'scripts/forge-deploy.sh');
    const result = spawnSync('bash', [candidateScript, 'prepare'], {
        cwd: fixture.releases['release-2'],
        encoding: 'utf8',
        env: {
            ...fixture.baseEnv,
            FORGE_RELEASE_DIRECTORY: fixture.releases['release-2'],
        },
        timeout: 5_000,
    });
    assert.equal(result.status, 0, result.stderr || result.stdout);

    const calls = await readFile(fixture.calls, 'utf8');
    assert.match(calls, /composer install --no-dev --optimize-autoloader --no-interaction/);
    assert.match(calls, /npm ci --ignore-scripts/);
    assert.match(calls, /npm run build/);
    assert.match(calls, /artisan migrate --force/);
    assert.match(calls, /artisan config:cache/);
});
