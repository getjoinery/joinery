<?php
/**
 * Joinery AI - My Memories (member)
 * URL: /profile/joinery_ai/memory
 *
 * A member's own AI memories (specs/joinery_ai_memory.md): everything the
 * assistant has remembered for them (badged AI) plus entries they added
 * themselves — list, add, edit, delete. Shared org memories are admin-managed
 * and don't appear here.
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/profile_memory_logic.php'));

$page_vars = process_logic(profile_joinery_ai_memory_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_edit = (bool)$memory->key;
$tz = $session->get_timezone();

$page = new PublicPage();
$page->public_header([
    'title' => 'AI Memory',
]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>AI Memory</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">My Profile</a></li>
                        <li class="active">AI Memory</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if (!empty($saved)): ?>
            <div class="alert alert-success">Saved.</div>
        <?php endif; ?>

        <h2><?php echo $is_edit ? 'Edit Memory' : 'Add a Memory'; ?></h2>
        <?php if ($is_edit && (string)$memory->get('mem_source') === AiMemory::SOURCE_AI): ?>
            <p><strong>AI</strong> — the assistant saved this memory; edit or delete it freely.</p>
        <?php endif; ?>
        <?php
        $formwriter = $page->getFormWriter('memory_form', [
            'model' => $memory,
            'edit_primary_key_value' => $memory->key,
            'action' => '/profile/joinery_ai/memory',
        ]);
        echo $formwriter->begin_form();

        $formwriter->textinput('mem_title', 'Title', [
            'maxlength' => 255,
            'helptext' => 'A short label the assistant sees in its memory index.',
        ]);

        $formwriter->textarea('mem_content', 'Content', [
            'rows' => 6,
            'required' => true,
        ]);

        $tags = $memory->get('mem_tags');
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tags)) $tags = [];

        $formwriter->textinput('mem_tags_text', 'Tags (comma-separated)', [
            'value' => implode(', ', $tags),
            'placeholder' => 'e.g. food, travel',
        ]);

        $formwriter->submitbutton('btn_submit', $is_edit ? 'Save' : 'Add Memory');
        if ($is_edit) {
            $formwriter->submitbutton('btn_delete', 'Delete', [
                'class'          => 'btn btn-outline-danger ms-2',
                'onclick'        => "return confirm('Delete this memory?');",
                'formnovalidate' => true,
            ]);
            echo ' <a class="btn btn-outline" href="/profile/joinery_ai/memory">Cancel</a>';
        }

        echo $formwriter->end_form();
        ?>

        <h2 class="jy-mt-2">Your Memories (<?php echo (int)$numrecords; ?>)</h2>
        <?php if (count($memories)): ?>
        <table class="jy-table jy-w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Content</th>
                    <th>Saved by</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($memories as $m): ?>
                <tr>
                    <td>
                        <?php
                        $mtitle = trim((string)$m->get('mem_title'));
                        echo $mtitle !== '' ? htmlspecialchars($mtitle) : '<em>(untitled)</em>';
                        ?>
                    </td>
                    <td>
                        <?php
                        $preview = trim((string)preg_replace('/\s+/', ' ', (string)$m->get('mem_content')));
                        if (mb_strlen($preview) > 80) $preview = mb_substr($preview, 0, 80) . '…';
                        echo htmlspecialchars($preview);
                        ?>
                    </td>
                    <td><?php echo (string)$m->get('mem_source') === AiMemory::SOURCE_AI ? 'AI' : 'You'; ?></td>
                    <td>
                        <?php
                        $when = $m->get('mem_update_time') ?: $m->get('mem_create_time');
                        echo $when ? htmlspecialchars(LibraryFunctions::convert_time($when, 'UTC', $tz, 'M j, Y')) : '';
                        ?>
                    </td>
                    <td><a href="/profile/joinery_ai/memory?mem_memory_id=<?php echo (int)$m->key; ?>">Edit</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pager->is_valid_page('-1') || $pager->is_valid_page('+1')): ?>
        <div class="jy-pagination">
            <div class="jy-pagination-links">
                <?php if ($pager->is_valid_page('-1')): ?>
                <a class="btn btn-outline" href="<?php echo $pager->get_url('-1', ''); ?>">&#8592; Previous</a>
                <?php endif; ?>
                <?php if ($pager->is_valid_page('+1')): ?>
                <a class="btn btn-outline" href="<?php echo $pager->get_url('+1', ''); ?>">Next &#8594;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <p>No memories yet. Ask the assistant to remember something, or add one above.</p>
        <?php endif; ?>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
