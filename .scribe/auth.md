# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a token from <code>POST /auth/login</code> with your email, password and a device name. The plain-text token is returned <b>once</b> — store it in secure storage (e.g. <code>flutter_secure_storage</code>), never in shared preferences. Tokens expire; rotate them with <code>POST /auth/refresh</code> before <code>expires_at</code>. A token can be issued with narrowed abilities (e.g. <code>["inventory"]</code>) so a shared warehouse tablet cannot reach finance endpoints even if its account could.
