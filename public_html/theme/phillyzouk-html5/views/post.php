<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

$page_vars = process_logic(post_logic($_GET, $_POST, $post));
$post = $page_vars['post'];
$session = $page_vars['session'];

$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => $is_valid_page,
    'title' => $post->get('pst_title')
);
$page->public_header($hoptions);
?>

<!-- Post Detail Area -->
<section class="post-detail-area">
    <div class="container">
        <div class="post-container">
            <div class="post-content">
                <div class="post-header">
                    <h1><?php echo htmlspecialchars($post->get('pst_title')); ?></h1>
                    <div class="post-meta">
                        <div class="post-meta-item">
                            <i class="bx bx-user"></i>
                            <a href="#"><?php echo htmlspecialchars($page_vars['author']->display_name()); ?></a>
                        </div>
                        <div class="post-meta-item">
                            <i class="bx bx-calendar"></i>
                            <?php echo date('M d, Y', strtotime($post->get('pst_published_time'))); ?>
                        </div>
                    </div>
                    <?php if (!empty($page_vars['tags'])): ?>
                    <span class="post-category"><?php echo htmlspecialchars($page_vars['tags'][0]); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Post Images -->
                <?php
                require_once(PathHelper::getIncludePath('includes/ComponentRenderer.php'));
                echo ComponentRenderer::render(null, 'image_gallery', [
                    'photos' => $post->get_photos(),
                    'primary_file_id' => $post->get('pst_fil_file_id'),
                    'alt_text' => $post->get('pst_title'),
                ]);
                ?>

                <!-- Post Body -->
                <div class="post-body">
                    <?php echo $post->get('pst_body'); ?>
                </div>

                <?php if($page_vars['settings']->get_setting('blog_footer_text')): ?>
                <div class="post-body" style="margin-top: 20px;">
                    <?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
                </div>
                <?php endif; ?>

                <!-- Post Footer -->
                <div class="post-footer">
                    <?php if (!empty($page_vars['tags'])): ?>
                    <div class="post-tags">
                        <strong>Tags:</strong>
                        <?php foreach ($page_vars['tags'] as $tag): ?>
                            <a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="article-share">
                        <ul class="social">
                            <li>
                                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $post->get_url()); ?>" target="_blank">f</a>
                            </li>
                            <li>
                                <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $post->get_url()); ?>&text=<?php echo urlencode($post->get('pst_title')); ?>" target="_blank">t</a>
                            </li>
                            <li>
                                <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $post->get_url()); ?>" target="_blank">in</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Post Navigation -->
                <?php
                $prev_posts = new MultiPost(
                    array('published' => TRUE, 'deleted' => false, 'before_date' => $post->get('pst_published_time')),
                    array('pst_published_time' => 'DESC'),
                    1, 0
                );
                $prev_post = null;
                if ($prev_posts->count_all() > 0) {
                    $prev_posts->load();
                    $prev_post = $prev_posts->get(0);
                }

                $next_posts = new MultiPost(
                    array('published' => TRUE, 'deleted' => false, 'after_date' => $post->get('pst_published_time')),
                    array('pst_published_time' => 'ASC'),
                    1, 0
                );
                $next_post = null;
                if ($next_posts->count_all() > 0) {
                    $next_posts->load();
                    $next_post = $next_posts->get(0);
                }
                ?>

                <?php if ($prev_post || $next_post): ?>
                <div class="post-navigation">
                    <div class="navigation-links">
                        <div class="nav-previous">
                            <?php if ($prev_post): ?>
                                <a href="<?php echo $prev_post->get_url(); ?>">&#8592; Prev Post</a>
                            <?php endif; ?>
                        </div>
                        <div class="nav-next">
                            <?php if ($next_post): ?>
                                <a href="<?php echo $next_post->get_url(); ?>">Next Post &#8594;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Comments Section -->
                <?php
                $settings = Globalvars::get_instance();
                if($settings->get_setting('comments_active')):
                ?>
                <div class="comments-section">
                    <?php if($settings->get_setting('show_comments') && $page_vars['numcomments']): ?>
                        <h2 class="comments-title"><?php echo $page_vars['numcomments']; ?> Comment<?php echo ($page_vars['numcomments'] != 1) ? 's' : ''; ?>:</h2>

                        <?php foreach($page_vars['comments'] as $comment): ?>
                        <div class="comment">
                            <div class="comment-author"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></div>
                            <div class="comment-date"><?php echo date('M d, Y \a\t g:i a', strtotime($comment->get('cmt_created_time'))); ?></div>
                            <div class="comment-text"><?php echo $comment->get_sanitized_comment(); ?></div>

                            <?php if($settings->get_setting('comments_unregistered_users') || $session->get_user_id()): ?>
                            <div style="margin-top: 10px;">
                                <a href="#" class="comment-reply-link" data-comment-id="<?php echo $comment->key; ?>">Reply</a>
                            </div>

                            <!-- Reply Form Container -->
                            <div id="reply-form-<?php echo $comment->key; ?>" class="reply-form-container" style="display:none;">
                                <?php
                                $formwriter = $page->getFormWriter('form'.$comment->key, ['action' => $_SERVER['REQUEST_URI']]);
                                $formwriter->begin_form();
                                $formwriter->hiddeninput('cmt_comment_id_parent', $comment->key);
                                ?>
                                <div class="form-group">
                                    <label>Your name</label>
                                    <?php $formwriter->textinput('name', '', ['maxlength' => 255]); ?>
                                </div>
                                <div class="form-group">
                                    <label>Your reply</label>
                                    <?php $formwriter->textbox('cmt', '', ['rows' => 3]); ?>
                                </div>
                                <?php if(!$session->get_user_id()): ?>
                                    <?php
                                    $formwriter->antispam_question_input('blog');
                                    $formwriter->honeypot_hidden_input();
                                    $formwriter->captcha_hidden_input('blog');
                                    ?>
                                <?php endif; ?>
                                <?php $formwriter->submitbutton('btn_submit', 'Reply', ['class' => 'btn-submit']); ?>
                                <?php $formwriter->end_form(); ?>
                            </div>
                            <?php endif; ?>

                            <!-- Nested Replies -->
                            <?php
                            $replies = new MultiComment(
                                array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false, 'parent_id'=>$comment->key),
                                array('comment_id'=>'DESC'),
                                NULL,
                                NULL
                            );
                            $numreplies = $replies->count_all();

                            if($numreplies):
                                $replies->load();
                            ?>
                            <div class="comment-reply">
                                <?php foreach($replies as $reply): ?>
                                    <?php if($reply->get('cmt_comment_id_parent') == $comment->key): ?>
                                    <div class="comment">
                                        <div class="comment-author"><?php echo htmlspecialchars($reply->get('cmt_author_name')); ?></div>
                                        <div class="comment-date"><?php echo date('M d, Y \a\t g:i a', strtotime($reply->get('cmt_created_time'))); ?></div>
                                        <div class="comment-text"><?php echo $reply->get_sanitized_comment(); ?></div>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Add Comment Form -->
                    <?php if($settings->get_setting('comments_unregistered_users') || $session->get_user_id()): ?>
                    <div class="comment-form">
                        <h3>Leave a Comment</h3>
                        <?php
                        $formwriter = $page->getFormWriter('form1', ['action' => $_SERVER['REQUEST_URI']]);
                        $formwriter->begin_form();
                        ?>
                        <div class="form-group">
                            <label>Name</label>
                            <?php $formwriter->textinput('name', '', ['maxlength' => 255]); ?>
                        </div>
                        <div class="form-group">
                            <label>Comment</label>
                            <?php $formwriter->textbox('cmt', '', ['rows' => 5]); ?>
                        </div>
                        <?php if(!$session->get_user_id()): ?>
                            <?php
                            $formwriter->antispam_question_input('blog');
                            $formwriter->honeypot_hidden_input();
                            $formwriter->honeypot_hidden_input('Comment', 'comment');
                            $formwriter->captcha_hidden_input('blog');
                            ?>
                        <?php endif; ?>
                        <div class="form-group">
                            <?php $formwriter->submitbutton('btn_submit', 'Post Comment', ['class' => 'btn-submit']); ?>
                        </div>
                        <?php $formwriter->end_form(); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <aside class="sidebar">
                <!-- Back to Blog -->
                <div class="sidebar-widget">
                    <a href="/blog" class="btn-back-to-blog">&#8592; Back to Blog</a>
                </div>

                <!-- Tags Widget -->
                <?php if (!empty($page_vars['tags'])): ?>
                <div class="sidebar-widget">
                    <h3>Tags</h3>
                    <div class="tag-cloud">
                        <?php foreach ($page_vars['tags'] as $tag): ?>
                            <a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Posts Widget -->
                <div class="sidebar-widget">
                    <h3>Recent Posts</h3>
                    <?php
                    $recent_posts = new MultiPost(
                        array('published' => TRUE, 'deleted' => false),
                        array('pst_published_time' => 'DESC'),
                        3, 0
                    );
                    $recent_posts->load();

                    foreach($recent_posts as $recent_post):
                    ?>
                    <div class="popular-post">
                        <h4><a href="<?php echo $recent_post->get_url(); ?>"><?php echo htmlspecialchars($recent_post->get('pst_title')); ?></a></h4>
                        <p><?php echo date('M d, Y', strtotime($recent_post->get('pst_published_time'))); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.comment-reply-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var commentId = this.getAttribute('data-comment-id');
            var formContainer = document.getElementById('reply-form-' + commentId);
            if (formContainer) {
                formContainer.style.display = formContainer.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
});
</script>

<?php
$page->public_footer();
?>
