<?php
// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('post_logic.php', 'logic'));

$page_vars = post_logic($_GET, $_POST, $post);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
$post = $page_vars['post'];

$page = new PublicPage();
$hoptions = array(
    'is_valid_page' => $is_valid_page,
    'title' => $post->get('pst_title')
);
$page->public_header($hoptions);
?>

<!-- Start Blog Details Area -->
<section class="blog-details-area ptb-100">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 col-md-12">
                <div class="blog-details-desc">
                    <div class="article-content">
                        <!-- Entry Meta -->
                        <div class="entry-meta">
                            <ul>
                                <li>
                                    <span>Posted On:</span>
                                    <a href="#"><?php echo date('M d, Y', strtotime($post->get('pst_published_time'))); ?></a>
                                </li>
                                <li>
                                    <span>Posted By:</span>
                                    <a href="#"><?php echo htmlspecialchars($page_vars['author']->display_name()); ?></a>
                                </li>
                            </ul>
                        </div>

                        <!-- Title -->
                        <h3><?php echo htmlspecialchars($post->get('pst_title')); ?></h3>

                        <!-- Featured Image -->
                        <?php if ($post->get('pst_image_link')): ?>
                        <div class="article-image">
                            <img src="<?php echo htmlspecialchars($post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                        </div>
                        <?php endif; ?>

                        <!-- Post Content -->
                        <div class="post-body-content">
                            <?php echo $post->get('pst_body'); ?>
                        </div>

                        <?php if($page_vars['settings']->get_setting('blog_footer_text')): ?>
                        <div class="blog-footer-text mt-4">
                            <?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Article Footer with Tags and Share -->
                    <div class="article-footer">
                        <div class="article-tags">
                            <?php if (!empty($page_vars['tags'])): ?>
                                <span><i class='bx bx-purchase-tag-alt'></i></span>
                                <?php foreach ($page_vars['tags'] as $tag): ?>
                                    <a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="article-share">
                            <ul class="social">
                                <li>
                                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $post->get_url()); ?>" target="_blank">
                                        <i class='bx bxl-facebook'></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $post->get_url()); ?>&text=<?php echo urlencode($post->get('pst_title')); ?>" target="_blank">
                                        <i class='bx bxl-twitter'></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $post->get_url()); ?>" target="_blank">
                                        <i class='bx bxl-linkedin'></i>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Post Navigation -->
                    <?php
                    // Get previous and next posts
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
                                    <a href="<?php echo $prev_post->get_url(); ?>">
                                        <i class='bx bx-left-arrow-alt'></i> Prev Post
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="nav-next">
                                <?php if ($next_post): ?>
                                    <a href="<?php echo $next_post->get_url(); ?>">
                                        Next Post <i class='bx bx-right-arrow-alt'></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Comments Section -->
                    <?php if($page_vars['settings']->get_setting('comments_active')): ?>
                    <div class="comments-area">
                        <?php if($page_vars['settings']->get_setting('show_comments') && $page_vars['numcomments']): ?>
                            <h3 class="comments-title"><?php echo $page_vars['numcomments']; ?> Comment<?php echo ($page_vars['numcomments'] != 1) ? 's' : ''; ?>:</h3>

                            <ol class="comment-list">
                                <?php foreach($page_vars['comments'] as $comment): ?>
                                <li class="comment">
                                    <div class="comment-body">
                                        <footer class="comment-meta">
                                            <div class="comment-author vcard">
                                                <img src="/includes/images/blank-avatar.png" class="avatar" alt="Avatar">
                                                <b class="fn"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></b>
                                                <span class="says">says:</span>
                                            </div>

                                            <div class="comment-metadata">
                                                <a href="#">
                                                    <span><?php echo date('M d, Y \a\t g:i a', strtotime($comment->get('cmt_created_time'))); ?></span>
                                                </a>
                                            </div>
                                        </footer>

                                        <div class="comment-content">
                                            <?php echo $comment->get_sanitized_comment(); ?>
                                        </div>

                                        <div class="reply">
                                            <a href="#" class="comment-reply-link" id="comment<?php echo $comment->key; ?>">Reply</a>
                                        </div>
                                    </div>

                                    <!-- Reply Form Container -->
                                    <?php if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
                                    <div id="comment<?php echo $comment->key; ?>container" style="display:none;" class="reply-form-container">
                                        <?php
                                        $formwriter = $page->getFormWriter('form'.$comment->key);

                                        $formwriter->begin_form([
                                            'id' => 'form'.$comment->key,
                                            'method' => 'POST',
                                            'action' => $_SERVER['REQUEST_URI'],
                                            'ajax' => true
                                        ]);

                                        $formwriter->hiddeninput('cmt_comment_id_parent', ['value' => $comment->key]);
                                        ?>

                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>Your name</label>
                                                    <?php
                                                    $formwriter->textinput('name', '', [
                                                        'class' => 'form-control',
                                                        'maxlength' => 255,
                                                        'required' => true
                                                    ]);
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>Your reply</label>
                                                    <?php
                                                    $formwriter->textbox('cmt', '', [
                                                        'class' => 'form-control',
                                                        'rows' => 3,
                                                        'required' => true
                                                    ]);
                                                    ?>
                                                </div>
                                            </div>

                                            <?php if(!$page_vars['session']->get_user_id()): ?>
                                            <div class="col-12">
                                                <?php
                                                $formwriter->antispam_question_input('blog');
                                                $formwriter->honeypot_hidden_input();
                                                $formwriter->captcha_hidden_input('blog');
                                                ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="col-md-12">
                                                <?php
                                                $formwriter->submitbutton('submit', 'Reply', [
                                                    'class' => 'default-btn'
                                                ]);
                                                ?>
                                            </div>
                                        </div>

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
                                        <ol class="children">
                                            <?php foreach($replies as $reply): ?>
                                                <?php if($reply->get('cmt_comment_id_parent') == $comment->key): ?>
                                                <li class="comment">
                                                    <div class="comment-body">
                                                        <footer class="comment-meta">
                                                            <div class="comment-author vcard">
                                                                <img src="/includes/images/blank-avatar.png" class="avatar" alt="Avatar">
                                                                <b class="fn"><?php echo htmlspecialchars($reply->get('cmt_author_name')); ?></b>
                                                                <span class="says">says:</span>
                                                            </div>

                                                            <div class="comment-metadata">
                                                                <a href="#">
                                                                    <span><?php echo date('M d, Y \a\t g:i a', strtotime($reply->get('cmt_created_time'))); ?></span>
                                                                </a>
                                                            </div>
                                                        </footer>

                                                        <div class="comment-content">
                                                            <?php echo $reply->get_sanitized_comment(); ?>
                                                        </div>
                                                    </div>
                                                </li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>

                        <!-- Add Comment Form -->
                        <?php if($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
                        <div class="comment-respond">
                            <h3 class="comment-reply-title">Leave a Reply</h3>

                            <?php
                            $settings = Globalvars::get_instance();
                            $formwriter = $page->getFormWriter('form1');

                            $formwriter->begin_form([
                                'id' => '',
                                'method' => 'POST',
                                'action' => $_SERVER['REQUEST_URI'],
                                'ajax' => true
                            ]);
                            ?>

                            <div class="row">
                                <div class="col-lg-6 col-md-12">
                                    <div class="form-group">
                                        <input type="text" name="name" class="form-control" placeholder="Your Name*" required>
                                    </div>
                                </div>

                                <div class="col-lg-12 col-md-12">
                                    <div class="form-group">
                                        <?php
                                        $formwriter->textbox('cmt', '', [
                                            'class' => 'form-control',
                                            'rows' => 5,
                                            'placeholder' => 'Your Comment',
                                            'required' => true
                                        ]);
                                        ?>
                                    </div>
                                </div>

                                <?php if(!$page_vars['session']->get_user_id()): ?>
                                <div class="col-12">
                                    <?php
                                    $formwriter->antispam_question_input('blog');
                                    $formwriter->honeypot_hidden_input();
                                    $formwriter->captcha_hidden_input('blog');
                                    ?>
                                </div>
                                <?php endif; ?>

                                <div class="col-lg-12 col-md-12">
                                    <?php
                                    $formwriter->submitbutton('submit', 'Post Comment', [
                                        'class' => 'default-btn'
                                    ]);
                                    ?>
                                </div>
                            </div>

                            <?php $formwriter->end_form(); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4 col-md-12">
                <aside class="widget-area">
                    <!-- Back to Blog -->
                    <div class="widget widget_back_to_blog">
                        <a href="/blog" class="default-btn">
                            <i class='bx bx-left-arrow-alt'></i> Back to Blog
                        </a>
                    </div>

                    <!-- Tags Widget -->
                    <?php if (!empty($page_vars['tags'])): ?>
                    <div class="widget widget_tag_cloud">
                        <h3 class="widget-title">Tags</h3>
                        <div class="tagcloud">
                            <?php foreach ($page_vars['tags'] as $tag): ?>
                                <a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Posts Widget -->
                    <div class="widget widget_linka_posts_thumb">
                        <h3 class="widget-title">Recent Posts</h3>

                        <?php
                        $recent_posts = new MultiPost(
                            array('published' => TRUE, 'deleted' => false),
                            array('pst_published_time' => 'DESC'),
                            3, 0
                        );
                        $recent_posts->load();

                        foreach($recent_posts as $recent_post):
                        ?>
                        <div class="item">
                            <a href="<?php echo $recent_post->get_url(); ?>" class="thumb">
                                <?php if ($recent_post->get('pst_image_link')): ?>
                                    <span class="fullimage cover" style="background-image: url('<?php echo htmlspecialchars($recent_post->get('pst_image_link')); ?>');"></span>
                                <?php else: ?>
                                    <span class="fullimage cover" style="background-image: url('https://via.placeholder.com/80x80/f8f9fa/6c757d?text=Post');"></span>
                                <?php endif; ?>
                            </a>
                            <div class="info">
                                <span><?php echo date('M d, Y', strtotime($recent_post->get('pst_published_time'))); ?></span>
                                <h4 class="title usmall">
                                    <a href="<?php echo $recent_post->get_url(); ?>">
                                        <?php echo htmlspecialchars($recent_post->get('pst_title')); ?>
                                    </a>
                                </h4>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</section>
<!-- End Blog Details Area -->

<script>
$(document).ready(function(){
    $('.comment-reply-link').click(function(e){
        e.preventDefault();
        var cid = $(this).attr('id');
        $('#' + cid + 'container').toggle(500);
    });
});
</script>

<?php
$page->public_footer();
?>
