'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const script = fs.readFileSync(`${__dirname}/../Resources/js/auth-alternative-coordinator.js`, 'utf8');

class Node {
    constructor(tagName) {
        this.tagName = tagName;
        this.attributes = Object.create(null);
        this.childNodes = [];
        this.parentNode = null;
        this._textContent = '';
    }

    appendChild(node) {
        if (node.parentNode) {
            node.parentNode.removeChild(node);
        }

        this.childNodes.push(node);
        node.parentNode = this;

        return node;
    }

    insertBefore(node, reference) {
        if (node.parentNode) {
            node.parentNode.removeChild(node);
        }

        const index = this.childNodes.indexOf(reference);
        if (-1 === index) {
            return this.appendChild(node);
        }

        this.childNodes.splice(index, 0, node);
        node.parentNode = this;

        return node;
    }

    removeChild(node) {
        const index = this.childNodes.indexOf(node);
        if (-1 !== index) {
            this.childNodes.splice(index, 1);
            node.parentNode = null;
        }

        return node;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }

    get nextSibling() {
        if (!this.parentNode) {
            return null;
        }

        const siblings = this.parentNode.childNodes;
        const index = siblings.indexOf(this);

        return -1 === index ? null : (siblings[index + 1] || null);
    }

    get textContent() {
        return this._textContent;
    }

    set textContent(value) {
        this._textContent = String(value);
    }
}

class Document {
    constructor() {
        this.body = new Node('body');
    }

    createElement(tagName) {
        return new Node(tagName);
    }

    querySelectorAll(selector) {
        const attribute = '[data-mautic-auth-alternative-block="1"]' === selector
            ? 'data-mautic-auth-alternative-block'
            : ('[data-mautic-auth-alternative-separator="1"]' === selector ? 'data-mautic-auth-alternative-separator' : null);
        const matches = [];

        if (!attribute) {
            return matches;
        }

        const visit = (node) => {
            if (null !== node.getAttribute(attribute)) {
                matches.push(node);
            }
            node.childNodes.forEach(visit);
        };
        this.body.childNodes.forEach(visit);

        return matches;
    }
}

function createEnvironment() {
    const document = new Document();
    const observers = [];
    const window = {
        document,
        MutationObserver: class {
            constructor(callback) {
                this.callback = callback;
                observers.push(this);
            }

            observe(target, options) {
                this.target = target;
                this.options = options;
            }
        },
        queueMicrotask: (callback) => callback(),
        setTimeout: (callback) => callback(),
    };

    return { document, observers, window };
}

function execute(environment) {
    const context = { window: environment.window };
    vm.runInNewContext(script, context);

    return environment.window.MauticAuthAlternativeCoordinator;
}

function block(environment, label, id) {
    const wrapper = environment.document.createElement('div');
    const node = environment.document.createElement('a');
    node.setAttribute('class', `mautic-auth-alternative-button ${id}`);
    node.setAttribute('data-mautic-auth-alternative', '1');
    node.textContent = id;
    wrapper.setAttribute('data-mautic-auth-alternative-block', '1');
    wrapper.setAttribute('data-mautic-auth-separator-label', label);
    wrapper.appendChild(node);

    return wrapper;
}

function separator(environment, text) {
    const node = environment.document.createElement('div');
    node.setAttribute('data-mautic-auth-alternative-separator', '1');
    node.textContent = text;

    return node;
}

function separators(environment) {
    return environment.document.querySelectorAll('[data-mautic-auth-alternative-separator="1"]');
}

function assertSeparatorBefore(environment, expectedBlock, expectedLabel) {
    const found = separators(environment);
    assert.strictEqual(found.length, 1, 'the page must contain exactly one shared separator');
    assert.strictEqual(found[0].parentNode, expectedBlock.parentNode);
    assert.strictEqual(found[0].nextSibling, expectedBlock, 'the separator must be immediately before the top wrapper');
    assert.strictEqual(found[0].textContent, expectedLabel);
}

{
    const environment = createEnvironment();
    const yandex = block(environment, 'or', 'yandexauth');
    environment.document.body.appendChild(yandex);

    execute(environment);
    assertSeparatorBefore(environment, yandex, 'or');
    assert.strictEqual(environment.observers.length, 1, 'the coordinator must install one observer');
}

{
    const environment = createEnvironment();
    const first = separator(environment, 'old 1');
    const second = separator(environment, 'old 2');
    const yandex = block(environment, 'or', 'yandexauth');
    const another = block(environment, 'или', 'another-auth');
    environment.document.body.appendChild(first);
    environment.document.body.appendChild(second);
    environment.document.body.appendChild(yandex);
    environment.document.body.appendChild(another);

    execute(environment);
    assertSeparatorBefore(environment, yandex, 'or');

    environment.document.body.insertBefore(another, first);
    environment.window.MauticAuthAlternativeCoordinator.coordinate();
    assertSeparatorBefore(environment, another, 'или');

    execute(environment);
    environment.window.MauticAuthAlternativeCoordinator.coordinate();
    assertSeparatorBefore(environment, another, 'или');
    assert.strictEqual(environment.observers.length, 1, 'repeated initialization must not add observers');
}

{
    const environment = createEnvironment();
    const yandex = block(environment, 'or', 'yandexauth');
    environment.document.body.appendChild(yandex);
    execute(environment);

    const newTopBlock = block(environment, 'или', 'another-auth');
    environment.document.body.insertBefore(newTopBlock, separators(environment)[0]);
    environment.observers[0].callback();
    assertSeparatorBefore(environment, newTopBlock, 'или');
}

console.log('Auth alternative coordinator tests passed');
