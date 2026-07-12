import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const DEFAULT_HOST = '127.0.0.1';
const DEFAULT_PORT = 4179;
const WEB_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const CONTENT_TYPES = new Map([
    ['.css', 'text/css; charset=utf-8'],
    ['.html', 'text/html; charset=utf-8'],
    ['.js', 'text/javascript; charset=utf-8'],
    ['.json', 'application/json; charset=utf-8'],
    ['.mjs', 'text/javascript; charset=utf-8'],
]);

export async function startVoiceV2TestServer({
    host = DEFAULT_HOST,
    port = DEFAULT_PORT,
} = {}) {
    const server = createServer(async (request, response) => {
        const url = new URL(request.url || '/', `http://${host}:${port}`);
        const requestedPath = url.pathname === '/'
            ? '/tests/browser/fixtures/voice-v2-harness.html'
            : decodeURIComponent(url.pathname);
        // Laravel serves files below `public` at the origin root. Preserve that
        // mapping for the real wake worklet/worker/model assets while keeping
        // source modules and test fixtures available to the browser harness.
        const absolutePath = requestedPath.startsWith('/voice/')
            ? path.resolve(WEB_ROOT, 'public', `.${requestedPath}`)
            : path.resolve(WEB_ROOT, `.${requestedPath}`);

        if (!absolutePath.startsWith(`${WEB_ROOT}${path.sep}`)) {
            response.writeHead(403).end('Forbidden');
            return;
        }

        try {
            const details = await stat(absolutePath);
            if (!details.isFile()) throw new Error('Not a file');
            response.writeHead(200, {
                'Cache-Control': 'no-store',
                'Content-Type': CONTENT_TYPES.get(path.extname(absolutePath)) || 'application/octet-stream',
            });
            createReadStream(absolutePath).pipe(response);
        } catch {
            response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }).end('Not found');
        }
    });

    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(port, host, resolve);
    });

    const address = server.address();
    const listeningPort = typeof address === 'object' && address
        ? address.port
        : port;

    return {
        origin: `http://${host}:${listeningPort}`,
        close: () => new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve())),
    };
}

const invokedDirectly = process.argv[1]
    && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href;

if (invokedDirectly) {
    const server = await startVoiceV2TestServer({
        host: process.env.VOICE_V2_TEST_HOST || DEFAULT_HOST,
        port: Number(process.env.VOICE_V2_TEST_PORT || DEFAULT_PORT),
    });
    process.stdout.write(`Bean Voice v2 browser harness: ${server.origin}\n`);

    const shutdown = async () => {
        await server.close();
        process.exit(0);
    };
    process.once('SIGINT', shutdown);
    process.once('SIGTERM', shutdown);
}
