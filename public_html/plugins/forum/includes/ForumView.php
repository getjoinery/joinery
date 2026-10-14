<?php
/**
 * ForumView — small pieces of markup the forum's pages share. Everything it
 * prints is escaped here; post bodies are the one exception, and those are
 * HTML MarkdownRenderer made at write time.
 *
 * @version 1.0.0
 */

class ForumView {

	/** "Members", "Staff only" … beside a category that is not public. */
	public static function accessBadge(ForumCategory $category): string {
		$labels = array(
			'members' => 'Members',
			'tier'    => 'Subscribers',
			'group'   => 'Group',
			'staff'   => 'Staff only',
		);
		$rule = (string)$category->get('fcg_read_rule');
		if (!isset($labels[$rule])) {
			return '';
		}
		return '<span class="forum-badge" title="Who can read this category">' . htmlspecialchars($labels[$rule]) . '</span>';
	}

	/** Breadcrumbs: Forum › category › topic. $trail is label => url ('' for the current page). */
	public static function crumbs(array $trail): string {
		$html = '<nav class="forum-crumbs" aria-label="Breadcrumb"><ol>';
		$html .= '<li><a href="/forum">Forum</a></li>';
		foreach ($trail as $label => $url) {
			if ($url === '') {
				$html .= '<li aria-current="page">' . htmlspecialchars($label) . '</li>';
			} else {
				$html .= '<li><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label) . '</a></li>';
			}
		}
		return $html . '</ol></nav>';
	}

	/**
	 * Page links. $url_for(int $page) builds each address.
	 */
	public static function pager(int $page, int $pages, callable $url_for): string {
		if ($pages <= 1) {
			return '';
		}
		$html = '<nav class="forum-pager" aria-label="Pages"><ul>';
		if ($page > 1) {
			$html .= '<li><a href="' . htmlspecialchars($url_for($page - 1)) . '" rel="prev">Previous</a></li>';
		}
		$shown = array();
		foreach (array(1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages) as $n) {
			if ($n >= 1 && $n <= $pages) {
				$shown[$n] = true;
			}
		}
		ksort($shown);
		$last = 0;
		foreach (array_keys($shown) as $n) {
			if ($last && $n > $last + 1) {
				$html .= '<li class="forum-pager-gap" aria-hidden="true">…</li>';
			}
			if ($n === $page) {
				$html .= '<li><span aria-current="page">' . $n . '</span></li>';
			} else {
				$html .= '<li><a href="' . htmlspecialchars($url_for($n)) . '">' . $n . '</a></li>';
			}
			$last = $n;
		}
		if ($page < $pages) {
			$html .= '<li><a href="' . htmlspecialchars($url_for($page + 1)) . '" rel="next">Next</a></li>';
		}
		return $html . '</ul></nav>';
	}

	/**
	 * The search box. Compact (a field and a button) on the forum's lists; the
	 * search page's full form adds the category and order choices.
	 *
	 * @param array $opts q, category_id, sort, full (bool), categories (id => name, for full)
	 */
	public static function searchForm(PublicPage $page, array $opts = array()): void {
		$full = !empty($opts['full']);
		$fw = $page->getFormWriter($full ? 'forum-search-full' : 'forum-search', array(
			'method' => 'get',
			'action' => '/forum/search',
			'class'  => $full ? 'forum-search-form' : 'forum-search-inline',
		));
		$fw->begin_form();
		$fw->textinput('q', $full ? 'Search for' : '', array(
			'value'       => (string)($opts['q'] ?? ''),
			'placeholder' => $full ? 'Words, "a phrase", -exclude' : (!empty($opts['category_id']) ? 'Search this category' : 'Search the forum'),
			'id'          => $full ? 'forum-search-q' : 'forum-q',
		));
		if ($full) {
			$fw->dropinput('category_id', 'Category', array(
				'options' => array('' => 'All categories') + (array)($opts['categories'] ?? array()),
				'value'   => !empty($opts['category_id']) ? (string)(int)$opts['category_id'] : '',
			));
			$fw->dropinput('sort', 'Order', array(
				'options' => array('best' => 'Best match', 'newest' => 'Newest first'),
				'value'   => ($opts['sort'] ?? 'best') === 'newest' ? 'newest' : 'best',
			));
		} elseif (!empty($opts['category_id'])) {
			$fw->hiddeninput('category_id', '', array('value' => (int)$opts['category_id']));
		}
		$fw->submitbutton('btn_search', 'Search', array('class' => $full ? 'btn btn-primary' : 'btn btn-secondary'));
		$fw->end_form();
	}

	/** The sign-in prompt where a signed-out visitor would write. */
	public static function signInPrompt(string $return_url, string $what): string {
		return '<p class="forum-signin-note"><a href="/login?return=' . rawurlencode($return_url) . '">Sign in</a> to '
			. htmlspecialchars($what) . '.</p>';
	}

	/** Initials for an author's avatar circle. */
	public static function initials(string $name): string {
		$parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
		$out = '';
		foreach (array_slice($parts, 0, 2) as $part) {
			$out .= mb_strtoupper(mb_substr($part, 0, 1));
		}
		return $out !== '' ? $out : '?';
	}

	/** A byte count people read ("2.4 MB"). */
	public static function size(int $bytes): string {
		return LibraryFunctions::format_bytes($bytes);
	}

	/**
	 * The writing box: title (for a new topic), the markdown editor in its
	 * restricted form with the @ picker, and the attachment picker. The page's
	 * script sends it through the API.
	 *
	 * @param string $mode 'topic', 'reply' or 'edit'
	 */
	public static function composer(PublicPage $page, string $mode, ForumCategory $category, array $context = array()): void {
		$form_id = 'forum-' . $mode . '-form';
		$fw = $page->getFormWriter($form_id, array('action' => $context['action_url'] ?? '#'));
		$attrs = 'class="forum-composer" data-forum-mode="' . htmlspecialchars($mode) . '"'
			. ' data-category-id="' . (int)$category->key . '"';
		if (!empty($context['topic_id'])) {
			$attrs .= ' data-topic-id="' . (int)$context['topic_id'] . '"';
		}
		echo '<div ' . $attrs . ' id="' . htmlspecialchars($form_id) . '-wrap"' . (!empty($context['hidden']) ? ' hidden' : '') . '>';
		$fw->begin_form();
		if ($mode === 'topic' || $mode === 'edit') {
			$fw->textinput('title', 'Title', array(
				'validation' => array('required' => $mode === 'topic', 'maxlength' => 200),
				'id'         => $form_id . '-title',
			));
		}
		echo '<div class="forum-reply-to" hidden><span></span> <button type="button" class="btn btn-sm btn-link" data-forum-clear-reply>Not a reply to this</button></div>';
		$fw->textbox('body', $mode === 'reply' ? 'Your reply' : ($mode === 'edit' ? 'Text' : 'Your post'), array(
			'markdownmode'        => 'yes',
			'markdown_restricted' => true,
			'mention_space'       => 'forum_category:' . (int)$category->key,
			'rows'                => $mode === 'reply' ? 6 : 10,
			'id'                  => $form_id . '-body',
		));
		if ($mode !== 'edit') {
			$fw->fileinput('files', 'Attach files', array(
				'multiple' => true,
				'id'       => $form_id . '-files',
				'helptext' => 'Up to ' . ForumUploads::MAX_PER_POST . ' files, ' . (int)(ForumUploads::maxBytes() / 1048576) . ' MB each.',
			));
			echo '<ul class="forum-attach-list" aria-live="polite"></ul>';
		}
		echo '<p class="forum-composer-error" role="alert" hidden></p>';
		echo '<div class="forum-composer-actions">';
		$fw->submitbutton('btn_submit', $mode === 'topic' ? 'Post topic' : ($mode === 'edit' ? 'Save' : 'Post reply'), array('class' => 'btn btn-primary'));
		if ($mode !== 'reply') {
			echo '<button type="button" class="btn btn-secondary" data-forum-cancel>Cancel</button>';
		}
		echo '</div>';
		$fw->end_form();
		echo '</div>';
	}
}
