<?php

/**
 * Base exception for every LLM provider. The recipe runner catches this type
 * (never a provider-specific subclass) so error handling is provider-agnostic.
 *
 * AnthropicException extends this for backward compatibility with any in-plugin
 * references; new providers throw LlmProviderException directly.
 */
class LlmProviderException extends Exception {}
