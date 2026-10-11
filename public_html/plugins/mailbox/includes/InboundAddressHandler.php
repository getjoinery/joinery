<?php
/**
 * InboundAddressHandler - what a plugin implements to receive mail addressed to one
 * of its features.
 *
 * A plugin declares the handler in plugin.json (`inboundHandlers`), and a store-mode
 * alias that names it (`iea_handler`) hands every new message to it after the message
 * is stored. See InboundHandlerRegistry and plugins/mailbox/docs/overview.md.
 *
 * @version 1.0
 */

interface InboundAddressHandler {
	/**
	 * Act on one stored message.
	 *
	 * @param InboundEmailMessage $msg the stored row (sender, subject, bodies, threading
	 *                                 headers and attachment Files, read the usual way)
	 * @param string|null $tag         the part after `+` in the address it arrived at, or
	 *                                 null when it came to the bare handler address
	 * @return bool true when the message was handled; false for "not mine"
	 */
	public function handle(InboundEmailMessage $msg, ?string $tag): bool;
}
