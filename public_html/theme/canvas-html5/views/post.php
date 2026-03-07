<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

    $page_vars = post_logic($_GET, $_POST, $post);
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;
    $post = $page_vars['post'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => $post->get('pst_title'),
    ]);
    echo PublicPage::BeginPage();
?>

<div class="container" style="padding: 2rem 1rem;">
    <div class="row gx-5">

        <main class="col-lg-9">
            <article>
                <div class="text-center mb-4">
                    <h1 class="mb-3"><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>
                    <p class="text-muted">
                        by <?php echo htmlspecialchars($page_vars['author']->display_name()); ?> &mdash;
                        <time><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York'); ?></time>
                    </p>
                    <?php if (!empty($page_vars['tags'])): ?>
                    <div class="d-flex justify-content-center flex-wrap gap-2 mb-3">
                        <?php foreach ($page_vars['tags'] as $tag): ?>
                        <a href="/blog/tag/<?php echo urlencode($tag); ?>" class="badge bg-light text-dark">
                            <?php echo htmlspecialchars($tag); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="entry-content">
                    <?php echo $post->get('pst_body'); ?>
                    <?php if ($page_vars['settings']->get_setting('blog_footer_text')): ?>
                    <div class="border-top pt-4 mt-5">
                        <?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
        </main>

        <aside class="col-lg-3">
            <div class="mb-4">
                <h4>Navigation</h4>
                <a href="/blog" class="btn btn-outline-primary w-100">&larr; Back to Blog</a>
            </div>

            <?php if (!empty($page_vars['tags'])): ?>
            <div class="mb-4">
                <h4>Tags</h4>
                <?php foreach ($page_vars['tags'] as $tag): ?>
                <a href="/blog/tag/<?php echo urlencode($tag); ?>" class="badge bg-light text-dark me-1 mb-1">
                    <?php echo htmlspecialchars($tag); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </aside>

    </div>

    <!-- Comments -->
    <?php if ($page_vars['settings']->get_setting('comments_active')): ?>
    <div class="row mt-5">
        <div class="col-lg-9">

            <?php if ($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
            <div class="card shadow-sm rounded-4 mb-5">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Add Comment</h4>
                </div>
                <div class="card-body p-4">
                    <?php
                    $formwriter = $page->getFormWriter('form1', ['action' => $_SERVER['REQUEST_URI']]);
                    $validation_rules = array();
                    $validation_rules['cmt']['required']['value'] = 'true';
                    $validation_rules['cmt']['minlength']['value'] = 20;
                    $validation_rules['cmt']['minlength']['message'] = "'Comment must be at least {0} characters'";
                    $validation_rules['name']['required']['value'] = 'true';
                    $validation_rules['name']['minlength']['value'] = 2;
                    $validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog');
                    $formwriter->begin_form();
                    ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <?php echo $formwriter->textinput('', 'name', 'form-control', 20, null, '', 255, ''); ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label for="cmt" class="form-label">Comment <span class="text-danger">*</span></label>
                                <?php echo $formwriter->textbox('', 'cmt', 'form-control', 4, 80, null, '', ''); ?>
                            </div>
                        </div>
                        <?php if (!$page_vars['session']->get_user_id()): ?>
                        <div class="col-12">
                            <?php
                            echo $formwriter->antispam_question_input('blog');
                            echo $formwriter->honeypot_hidden_input();
                            echo $formwriter->honeypot_hidden_input('Comment', 'comment');
                            echo $formwriter->captcha_hidden_input('blog');
                            ?>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 text-end">
                            <?php echo $formwriter->submitbutton('submit', 'Post Comment', ['class' => 'btn btn-primary']); ?>
                        </div>
                    </div>

                    <?php echo $formwriter->end_form(true); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($page_vars['settings']->get_setting('show_comments') && $page_vars['numcomments']): ?>
            <div class="card shadow-sm rounded-4">
                <div class="card-header bg-light">
                    <h4 class="mb-0">Comments (<?php echo $page_vars['numcomments']; ?>)</h4>
                </div>
                <div class="card-body p-0">
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.commentbutton').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var container = document.getElementById(this.id + 'container');
                                if (container) container.style.display = container.style.display === 'none' ? 'block' : 'none';
                            });
                        });
                    });
                    </script>

                    <?php foreach ($page_vars['comments'] as $comment): ?>
                    <div class="border-bottom p-4">
                        <div class="d-flex align-items-start">
                            <img class="rounded-circle me-3" src="/includes/images/blank-avatar.png" width="50" height="50" alt="Avatar">
                            <div class="flex-grow-1">
                                <h6 class="mb-0"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></h6>
                                <small class="text-muted"><?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></small>
                                <div class="mt-2 mb-3"><?php echo $comment->get_sanitized_comment(); ?></div>
                                <button id="comment<?php echo $comment->key; ?>" class="commentbutton btn btn-outline btn-sm">Reply</button>

                                <?php if ($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
                                <div id="comment<?php echo $comment->key; ?>container" style="display:none;" class="mt-3 p-3 bg-light" style="border-radius: 4px;">
                                    <?php
                                    $reply_fw = LibraryFunctions::get_formwriter_object('form' . $comment->key);
                                    $reply_rules = array();
                                    $reply_rules['cmt']['required']['value'] = 'true';
                                    $reply_rules['cmt']['minlength']['value'] = 20;
                                    $reply_rules['name']['required']['value'] = 'true';
                                    $reply_rules['name']['minlength']['value'] = 2;
                                    $reply_rules = $reply_fw->antispam_question_validate($reply_rules, 'blog');
                                    echo $reply_fw->begin_form('form' . $comment->key, 'post', $_SERVER['REQUEST_URI'], true);
                                    echo $reply_fw->hiddeninput('cmt_comment_id_parent', $comment->key);
                                    ?>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="form-label">Your name</label>
                                                <?php echo $reply_fw->textinput('', 'name', 'form-control', 20, null, '', 255, ''); ?>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group">
                                                <label class="form-label">Your reply</label>
                                                <?php echo $reply_fw->textbox('', 'cmt', 'form-control', 3, 80, null, '', ''); ?>
                                            </div>
                                        </div>
                                        <?php if (!$page_vars['session']->get_user_id()): ?>
                                        <div class="col-12">
                                            <?php
                                            echo $reply_fw->antispam_question_input('blog');
                                            echo $reply_fw->honeypot_hidden_input();
                                            echo $reply_fw->honeypot_hidden_input('Comment', 'comment');
                                            echo $reply_fw->captcha_hidden_input('blog');
                                            ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-12 text-end">
                                            <?php echo $reply_fw->submitbutton('submit', 'Reply', ['class' => 'btn btn-primary']); ?>
                                        </div>
                                    </div>
                                    <?php echo $reply_fw->end_form(true); ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                $replies = new MultiComment(
                                    ['post_id' => $post->key, 'approved' => true, 'deleted' => false, 'parent_id' => $comment->key],
                                    ['comment_id' => 'DESC']
                                );
                                $numreplies = $replies->count_all();
                                if ($numreplies) {
                                    $replies->load();
                                    ?>
                                    <div class="mt-3">
                                        <?php foreach ($replies as $reply):
                                            if ($reply->get('cmt_comment_id_parent') == $comment->key): ?>
                                            <div class="d-flex align-items-start mt-3 ms-4">
                                                <img class="rounded-circle me-3" src="/includes/images/blank-avatar.png" width="40" height="40" alt="Avatar">
                                                <div class="flex-grow-1 bg-light p-3" style="border-radius: 4px;">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <strong class="small"><?php echo htmlspecialchars($reply->get('cmt_author_name')); ?></strong>
                                                        <small class="text-muted"><?php echo LibraryFunctions::convert_time($reply->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></small>
                                                    </div>
                                                    <div class="small"><?php echo $reply->get_sanitized_comment(); ?></div>
                                                </div>
                                            </div>
                                            <?php endif;
                                        endforeach; ?>
                                    </div>
                                    <?php
                                }
                                ?>

                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

</div>

<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
