<?php
/**
 * OAuth2Exception - Typed failure for OAuth2 grant/refresh errors.
 *
 * Thrown by OAuth2Client when a token-endpoint request fails (non-2xx
 * response, network error, or unparseable body) and by the callback when a
 * flow cannot be completed. Consumers catch it to record a status rather than
 * crashing a batch job. The message is safe to log — it never contains a
 * client secret, access token, or refresh token.
 *
 * @version 1.0
 */
class OAuth2Exception extends Exception {
}
