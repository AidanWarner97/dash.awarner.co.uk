function decodeBase64Url(value) {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4);
    return Uint8Array.from(atob(padded), character => character.charCodeAt(0));
}

function encodeBase64Url(value) {
    const bytes = new Uint8Array(value);
    let binary = '';
    bytes.forEach(byte => binary += String.fromCharCode(byte));
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function prepareOptions(options) {
    options.challenge = decodeBase64Url(options.challenge);
    if (options.user?.id) options.user.id = decodeBase64Url(options.user.id);
    if (options.excludeCredentials) options.excludeCredentials.forEach(credential => credential.id = decodeBase64Url(credential.id));
    if (options.allowCredentials) options.allowCredentials.forEach(credential => credential.id = decodeBase64Url(credential.id));
    return options;
}

function serializeCredential(credential) {
    const response = {
        rawId: encodeBase64Url(credential.rawId),
        clientDataJSON: encodeBase64Url(credential.response.clientDataJSON),
    };
    if (credential.response.attestationObject) {
        response.attestationObject = encodeBase64Url(credential.response.attestationObject);
        response.transports = credential.response.getTransports?.() ?? [];
    } else {
        response.authenticatorData = encodeBase64Url(credential.response.authenticatorData);
        response.signature = encodeBase64Url(credential.response.signature);
        response.userHandle = credential.response.userHandle ? encodeBase64Url(credential.response.userHandle) : '';
    }
    return response;
}

async function runPasskey(mode, button) {
    const message = document.querySelector('.auth-message');
    if (!window.PublicKeyCredential || !navigator.credentials) throw new Error('Passkeys are not supported by this browser.');
    button.disabled = true;
    if (message) message.textContent = mode === 'register' ? 'Waiting for your authenticator…' : 'Choose a passkey…';
    try {
        const csrfToken = button.dataset.csrf ?? '';
        const optionsResponse = await fetch('/auth/passkey.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'options', mode, csrf_token: csrfToken }) });
        const optionsResult = await optionsResponse.json();
        if (!optionsResult.ok) throw new Error(optionsResult.message);
        const publicKey = prepareOptions(optionsResult.options.publicKey);
        const credential = mode === 'register' ? await navigator.credentials.create({ publicKey }) : await navigator.credentials.get({ publicKey });
        const payload = { action: 'verify', mode, csrf_token: csrfToken, credential: serializeCredential(credential) };
        if (mode === 'register') payload.label = document.querySelector('#passkey-label')?.value ?? 'My passkey';
        else payload.return = button.dataset.return ?? '/';
        const verifyResponse = await fetch('/auth/passkey.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const verifyResult = await verifyResponse.json();
        if (!verifyResult.ok) throw new Error(verifyResult.message);
        window.location.assign(verifyResult.redirect);
    } catch (error) {
        if (message) message.textContent = error.name === 'NotAllowedError' ? 'Passkey request cancelled.' : error.message;
    } finally {
        button.disabled = false;
    }
}

document.querySelector('[data-passkey-login]')?.addEventListener('click', event => runPasskey('login', event.currentTarget));
document.querySelector('[data-passkey-register]')?.addEventListener('click', event => runPasskey('register', event.currentTarget));
window.lucide?.createIcons();