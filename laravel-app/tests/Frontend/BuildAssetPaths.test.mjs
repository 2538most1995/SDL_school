import assert from 'node:assert/strict';
import { access, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const buildRoot = fileURLToPath(new URL('../../public/build/', import.meta.url));

test('production classroom CSS and JS preloads stay inside the deployed application directory', async () => {
    const manifest = JSON.parse(await readFile(path.join(buildRoot, 'manifest.json'), 'utf8'));
    const app = manifest['resources/js/app.tsx'];
    const classroom = manifest['resources/js/features/learning/OnlineClassroomPage.tsx'];
    const helper = Object.values(manifest).find(entry => entry.name === 'preload-helper');
    assert.ok(app && classroom && helper, 'Production build must contain the app, classroom and preload helper');
    assert.ok(classroom.css?.length, 'Exercise the classroom lazy stylesheet which previously broke subdirectory deployments');

    const source = await readFile(path.join(buildRoot, app.file), 'utf8');
    // Use the actual generated dependency map, rather than inventing paths that
    // could conceal a mismatch between the Vite configuration and its caller.
    const dependencyArray = source.match(/\[(?:"[^"\n]*"(?:,\s*)?)+\]/)?.[0];
    assert.ok(dependencyArray, 'Production app must declare its lazy dependency map');
    const dependencies = JSON.parse(dependencyArray);
    const cssDependency = dependencies.find(dependency => dependency.endsWith(path.basename(classroom.css[0])));
    const jsDependency = dependencies.find(dependency => dependency.endsWith(path.basename(classroom.file)));
    assert.ok(cssDependency && jsDependency, 'Classroom JS and CSS must both be present in the app dependency map');
    assert.ok(/,\s*import\.meta\.url\s*\)/.test(source), 'Relative preload calls must receive the importing module URL');

    const module = await import(pathToFileURL(path.join(buildRoot, helper.file)).href);
    const exportedFunctions = Object.values(module).filter(value => typeof value === 'function');
    assert.equal(exportedFunctions.length, 1, 'Resolve the emitted preload function without depending on its minified export name');
    const preload = exportedFunctions[0];
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    try {
        for (const prefix of ['', '/SDL_school', '/campus/SDL_school']) {
            const links = [];
            globalThis.document = {
                getElementsByTagName: () => [],
                querySelector: () => null,
                createElement: () => ({
                    events: {},
                    setAttribute(name, value) { this[name] = value; },
                    addEventListener(name, callback) { this.events[name] = callback; },
                }),
                head: {
                    appendChild(link) {
                        links.push(link);
                        queueMicrotask(() => link.events.load?.());
                    },
                },
            };
            globalThis.window = { dispatchEvent: () => true };
            const origin = 'https://school.example.test';
            const importer = `${origin}${prefix}/build/${app.file}`;
            const result = await preload(() => Promise.resolve('classroom loaded'), [cssDependency, jsDependency], importer);
            assert.equal(result, 'classroom loaded');
            assert.equal(links.find(link => link.rel === 'stylesheet')?.href, `${origin}${prefix}/build/${classroom.css[0]}`);
            assert.equal(links.find(link => link.rel === 'modulepreload')?.href, `${origin}${prefix}/build/${classroom.file}`);
        }
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
});

test('production CSS font and image URLs stay inside root and nested deployment directories', async () => {
    const manifest = JSON.parse(await readFile(path.join(buildRoot, 'manifest.json'), 'utf8'));
    const stylesheets = Object.values(manifest).filter(entry => entry.file.endsWith('.css'));
    const checkedFiles = new Set();
    for (const stylesheet of stylesheets) {
        const source = await readFile(path.join(buildRoot, stylesheet.file), 'utf8');
        for (const match of source.matchAll(/url\(([^)]+)\)/g)) {
            const dependency = match[1].trim().replace(/^["']|["']$/g, '');
            if (/^(?:data:|https?:|\/\/)/i.test(dependency)) continue;
            for (const prefix of ['', '/SDL_school', '/campus/SDL_school']) {
                const buildUrl = `https://school.example.test${prefix}/build/`;
                const resolved = new URL(dependency, buildUrl + stylesheet.file);
                assert.ok(resolved.href.startsWith(buildUrl), `CSS asset escaped ${prefix || '/'} deployment: ${dependency}`);
                const relativeFile = decodeURIComponent(resolved.pathname.slice(new URL(buildUrl).pathname.length));
                if (!checkedFiles.has(relativeFile)) {
                    await access(path.join(buildRoot, relativeFile));
                    checkedFiles.add(relativeFile);
                }
            }
        }
    }
    assert.ok(checkedFiles.size > 0, 'Exercise the actual production font/image assets');
});
