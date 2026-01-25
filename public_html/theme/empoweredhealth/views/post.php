<?php
// theme/empoweredhealth/views/post.php
// Theme-specific single post template with empoweredhealth styling

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
$author = $page_vars['author'];
$tags = $page_vars['tags'];

$paget = new PublicPage();
$paget->public_header(array(
    'is_valid_page' => $is_valid_page,
    'title' => $post->get('pst_title')
));
?>

<!-- Banner Section -->
<section class="banner-area relative about-banner" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row d-flex align-items-center justify-content-center">
            <div class="about-content col-lg-12">
                <h1 class="text-white">Blog</h1>
                <p class="text-white link-nav">
                    <a href="/">Home </a><span class="lnr lnr-arrow-right"></span>
                    Blog
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Blog Post Content Section -->
<section class="post-content-area single-post-area">
    <div class="container">
        <div class="row">
            <!-- Main Post Content -->
            <div class="col-lg-8 posts-list">
                <div class="single-post row">
                    <div class="col-lg-3 col-md-3 meta-details">
                        <ul class="tags">
                            <?php if (!empty($tags)): ?>
                                <?php foreach ($tags as $tag): ?>
                                    <li><a href="/blog/tag/<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?>,</a></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li><a href="#">Health,</a></li>
                            <?php endif; ?>
                        </ul>
                        <div class="user-details row">
                            <p class="user-name col-lg-12 col-md-12 col-6"><a href="#"><?php echo htmlspecialchars($author->display_name()); ?></a> <span class="lnr lnr-user"></span></p>
                            <p class="date col-lg-12 col-md-12 col-6"><a href="#"><?php echo date('M j, g:i a', strtotime($post->get('pst_published_time'))); ?></a> <span class="lnr lnr-calendar-full"></span></p>
                        </div>
                    </div>
                    <div class="col-lg-9 col-md-9">
                        <h3 class="mt-20 mb-20"><?php echo htmlspecialchars($post->get('pst_title')); ?></h3>
                        <?php echo $post->get('pst_body'); ?>

                        <?php if ($page_vars['settings']->get_setting('blog_footer_text')): ?>
                        <div class="blog-footer-text mt-4 pt-4 border-top">
                            <?php echo $page_vars['settings']->get_setting('blog_footer_text'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Comments Section -->
                <?php if ($page_vars['settings']->get_setting('comments_active')): ?>
                <div class="comments-area mt-5">
                    <?php if ($page_vars['settings']->get_setting('comments_unregistered_users') || $page_vars['session']->get_user_id()): ?>
                    <!-- Add Comment Form -->
                    <div class="comment-form mb-5">
                        <h4 class="mb-4">Leave a Comment</h4>
                        <?php
                        $formwriter = $paget->getFormWriter('form1');

                        $formwriter->begin_form([
                            'id' => '',
                            'method' => 'POST',
                            'action' => $_SERVER['REQUEST_URI'],
                            'ajax' => true
                        ]);
                        ?>
                        <div class="row">
                            <div class="col-md-6">
                                <?php
                                $formwriter->textinput('name', 'Name', [
                                    'class' => 'form-control',
                                    'maxlength' => 255,
                                    'required' => true,
                                    'minlength' => 2,
                                    'placeholder' => 'Your Name'
                                ]);
                                ?>
                            </div>
                            <div class="col-12 mt-3">
                                <?php
                                $formwriter->textbox('cmt', 'Comment', [
                                    'class' => 'form-control',
                                    'rows' => 5,
                                    'required' => true,
                                    'minlength' => 20,
                                    'placeholder' => 'Your comment...'
                                ]);
                                ?>
                            </div>

                            <?php if (!$page_vars['session']->get_user_id()): ?>
                            <div class="col-12 mt-3">
                                <?php
                                $formwriter->antispam_question_input('blog');
                                $formwriter->honeypot_hidden_input();
                                $formwriter->captcha_hidden_input('blog');
                                ?>
                            </div>
                            <?php endif; ?>

                            <div class="col-12 mt-3">
                                <?php
                                $formwriter->submitbutton('submit', 'Post Comment', [
                                    'class' => 'primary-btn'
                                ]);
                                ?>
                            </div>
                        </div>
                        <?php $formwriter->end_form(); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Display Comments -->
                    <?php if ($page_vars['settings']->get_setting('show_comments') && $page_vars['numcomments']): ?>
                    <div class="existing-comments">
                        <h4 class="mb-4">Comments (<?php echo $page_vars['numcomments']; ?>)</h4>
                        <?php foreach ($page_vars['comments'] as $comment): ?>
                        <div class="comment-item mb-4 pb-4 border-bottom">
                            <div class="d-flex">
                                <img class="rounded-circle mr-3" src="/includes/images/blank-avatar.png" width="50" height="50" alt="Avatar" style="margin-right: 15px;">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($comment->get('cmt_author_name')); ?></h6>
                                    <small class="text-muted"><?php echo LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', 'America/New_York'); ?></small>
                                    <p class="mt-2"><?php echo $comment->get_sanitized_comment(); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4 sidebar-widgets">
                <!-- Author Profile Widget -->
                <div class="widget-wrap">
                    <div class="about-widget text-center">
                        <img class="author-img" src="/uploads/medium/heath1_z0wwvdqa.jpeg" alt="Heath Tunnell Picture">
                        <a href="#">
                            <h4>Heath Tunnell</h4>
                        </a>
                        <p class="company">Empowered Health</p>
                        <ul class="social-links">
                            <li><a href="https://www.facebook.com/Empowered-Health-LLC-103774771240994/"><i class="fa fa-facebook"></i></a></li>
                            <li><a href="https://twitter.com/EmpoweredHealt2"><i class="fa fa-twitter"></i></a></li>
                            <li><a href="https://www.instagram.com/empoweredhealthtn/"><i class="fa fa-instagram"></i></a></li>
                        </ul>
                        <p class="about-text">Empowered Health is a new, innovative concierge medicine service in Knoxville, TN. Imagine going to your healthcare provider and enjoying the experience, not feeling rushed. At Empowered Health, you have a provider who takes interest in all aspects of your life because your well-being isn't just physical, but mental and emotional as well.</p>
                    </div>
                </div>

                <!-- Newsletter Widget -->
                <div class="widget-wrap">
                    <div class="single-sidebar-widget newsletter-widget">
                        <h4 class="newsletter-title">Newsletter Signup</h4>
                        <p>&nbsp;</p>
                        <div class="form-group d-flex flex-row">
                            <div class="col-autos">
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fa fa-envelope" aria-hidden="true"></i></div>
                                    </div>
                                    <input type="text" class="form-control" placeholder="Enter email">
                                </div>
                            </div>
                            <a href="/lists" class="bbtns">Subscribe</a>
                        </div>
                        <p class="text-bottom">You can unsubscribe at any time</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$paget->public_footer(array('track' => TRUE));
?>
