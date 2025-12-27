<?php
/**
 * Single post page for Linka Reference Theme
 *
 * @version 1.0.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();

// Get post data if available
$post = isset($page_vars['post']) ? $page_vars['post'] : null;
$post_title = $post ? $post['title'] : 'Blog Post';

$page->public_header(array(
    'title' => $post_title . ' - ' . $settings->get_setting('site_name', true, true),
    'showheader' => true
));
?>

<!-- Start Page Title Area -->
<div class="page-title-area bg-12">
    <div class="container">
        <div class="page-title-content">
            <h2><?php echo htmlspecialchars($post_title); ?></h2>
            <ul>
                <li>
                    <a href="/">Home</a>
                </li>
                <li>
                    <a href="/blog">Blog</a>
                </li>
                <li><?php echo htmlspecialchars($post_title); ?></li>
            </ul>
        </div>
    </div>
</div>
<!-- End Page Title Area -->

<!-- Start Blog Details Area -->
<section class="blog-details-area ptb-100">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 col-md-12">
                <div class="blog-details-desc">
                    <div class="article-content">
                        <div class="entry-meta">
                            <ul>
                                <li><span>Posted On:</span> <a href="#"><?php echo $post ? htmlspecialchars($post['date']) : date('M d, Y'); ?></a></li>
                                <li><span>Posted By:</span> <a href="#"><?php echo $post ? htmlspecialchars($post['author']) : 'Admin'; ?></a></li>
                            </ul>
                        </div>

                        <h3><?php echo htmlspecialchars($post_title); ?></h3>

                        <div class="article-image">
                            <img src="<?php echo $post ? htmlspecialchars($post['image']) : '/theme/linka-reference/assets/images/blog-details/1.jpg'; ?>" alt="<?php echo htmlspecialchars($post_title); ?>">
                        </div>

                        <?php if ($post && isset($post['content'])): ?>
                            <?php echo $post['content']; ?>
                        <?php else: ?>
                            <p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>

                            <p>Duis aute irure dolor in reprehenderit in sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat labore et dolore magna aliqua.</p>

                            <blockquote class="flaticon-quote">
                                <p>Lorem, ipsum dolor sit amet consectetur adipisicing elit. Repellendus aliquid praesentium eveniet illum asperiores, quidem, ipsum voluptatum numquam ducimus nisi exercitationem dolorum facilis Repellendus aliquid praesentium eveniet illum asperiores.</p>
                            </blockquote>

                            <p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>

                            <h3>Additional Insights</h3>

                            <div class="article-image">
                                <img src="/theme/linka-reference/assets/images/blog-details/2.jpg" alt="image">
                            </div>

                            <p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
                        <?php endif; ?>

                        <h3 class="related-posts">Related Posts</h3>
                        <div class="row">
                            <div class="col-lg-6 col-sm-6">
                                <div class="b-d-s-item">
                                    <a href="#">
                                        <img src="/theme/linka-reference/assets/images/blog-img/1.jpg" alt="Image">
                                        <span class="s-date">
                                            08 <br> Jun
                                        </span>
                                        <h3>Why We Need Guidelines For Brain Scan Data</h3>
                                    </a>

                                    <p>Lorem ipsum, dolor sit amet consectetur adipisicing elit.</p>

                                    <a href="#">Read More</a>
                                </div>
                            </div>
                            <div class="col-lg-6 col-sm-6">
                                <div class="b-d-s-item mb-0">
                                    <a href="#">
                                        <img src="/theme/linka-reference/assets/images/blog-img/2.jpg" alt="Image">
                                        <span class="s-date">
                                            09 <br> Jun
                                        </span>
                                        <h3>How To Build Artificial Intelligence You Can Trust</h3>
                                    </a>

                                    <p>Lorem ipsum, dolor sit amet consectetur adipisicing elit.</p>

                                    <a href="#">Read More</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="article-footer">
                        <div class="article-tags">
                            <span><i class='bx bx-share-alt'></i></span>
                            <a href="#">Share</a>
                        </div>

                        <div class="article-share">
                            <ul class="social">
                                <li>
                                    <a href="#" target="_blank">
                                        <i class='bx bxl-facebook'></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" target="_blank">
                                        <i class='bx bxl-twitter'></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" target="_blank">
                                        <i class='bx bxl-linkedin'></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" target="_blank">
                                        <i class='bx bxl-pinterest-alt'></i>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="post-navigation">
                        <div class="navigation-links">
                            <div class="nav-previous">
                                <a href="#"><i class='bx bx-left-arrow-alt'></i> Prev Post</a>
                            </div>
                            <div class="nav-next">
                                <a href="#">Next Post <i class='bx bx-right-arrow-alt'></i></a>
                            </div>
                        </div>
                    </div>

                    <div class="comments-area">
                        <h3 class="comments-title">Comments:</h3>

                        <div class="comment-respond">
                            <h3 class="comment-reply-title">Leave a Reply</h3>

                            <form class="comment-form" action="/ajax/comment" method="POST">
                                <p class="comment-notes">
                                    <span id="email-notes">Your email address will not be published.</span>
                                    Required fields are marked
                                    <span class="required">*</span>
                                </p>
                                <p class="comment-form-author">
                                    <label>Name <span class="required">*</span></label>
                                    <input type="text" id="author" name="author" required="required">
                                </p>
                                <p class="comment-form-email">
                                    <label>Email <span class="required">*</span></label>
                                    <input type="email" id="email" name="email" required="required">
                                </p>
                                <p class="comment-form-url">
                                    <label>Website</label>
                                    <input type="url" id="url" name="url">
                                </p>
                                <p class="comment-form-comment">
                                    <label>Comment</label>
                                    <textarea name="comment" id="comment" cols="45" rows="5" maxlength="65525" required="required"></textarea>
                                </p>
                                <p class="form-submit">
                                    <input type="submit" name="submit" id="submit" class="submit" value="Post A Comment">
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-12">
                <aside class="widget-area" id="secondary">
                    <section class="widget widget-peru-posts-thumb">
                        <h3 class="widget-title">Popular Posts</h3>
                        <div class="post-wrap">
                            <article class="item">
                                <a href="#" class="thumb">
                                    <span class="fullimage cover" role="img" style="background-image: url('/theme/linka-reference/assets/images/blog-img/1.jpg');"></span>
                                </a>
                                <div class="info">
                                    <time datetime="2024-06-30"><?php echo date('M d, Y'); ?></time>
                                    <h4 class="title usmall">
                                        <a href="#">The Two Most Important Tools To Reconnect</a>
                                    </h4>
                                </div>
                                <div class="clear"></div>
                            </article>

                            <article class="item">
                                <a href="#" class="thumb">
                                    <span class="fullimage cover" role="img" style="background-image: url('/theme/linka-reference/assets/images/blog-img/2.jpg');"></span>
                                </a>
                                <div class="info">
                                    <time datetime="2024-06-30"><?php echo date('M d, Y'); ?></time>
                                    <h4 class="title usmall">
                                        <a href="#">Genderless Kei – Japan's Hot New Fashion Trend</a>
                                    </h4>
                                </div>
                                <div class="clear"></div>
                            </article>

                            <article class="item">
                                <a href="#" class="thumb">
                                    <span class="fullimage cover" role="img" style="background-image: url('/theme/linka-reference/assets/images/blog-img/1.jpg');"></span>
                                </a>
                                <div class="info">
                                    <time datetime="2024-06-30"><?php echo date('M d, Y'); ?></time>
                                    <h4 class="title usmall">
                                        <a href="#">Security In A Fragment World Of Workload</a>
                                    </h4>
                                </div>
                                <div class="clear"></div>
                            </article>
                        </div>
                    </section>

                    <section class="widget widget_categories">
                        <h3 class="widget-title">Categories</h3>
                        <div class="post-wrap">
                            <ul>
                                <li><a href="#">World News <span>(10)</span></a></li>
                                <li><a href="#">Politics News <span>(20)</span></a></li>
                                <li><a href="#">Family News <span>(10)</span></a></li>
                                <li><a href="#">Global news <span>(12)</span></a></li>
                                <li><a href="#">Business <span>(16)</span></a></li>
                                <li><a href="#">Fashion <span>(17)</span></a></li>
                            </ul>
                        </div>
                    </section>

                    <section class="widget widget_tag_cloud">
                        <h3 class="widget-title">Tags</h3>
                        <div class="post-wrap">
                            <div class="tagcloud">
                                <a href="#">World News (3)</a>
                                <a href="#">Politics News (3)</a>
                                <a href="#">Family News (2)</a>
                                <a href="#">Global news (2)</a>
                                <a href="#">Fashion (2)</a>
                            </div>
                        </div>
                    </section>

                    <div class="follows-area widget">
                        <ul>
                            <?php if ($settings->get_setting('social_facebook_link')): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($settings->get_setting('social_facebook_link')); ?>" target="_blank">
                                    Facebook <br>
                                    <span>Like us</span>
                                    <i class="bx bxl-facebook"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if ($settings->get_setting('social_twitter_link')): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($settings->get_setting('social_twitter_link')); ?>" target="_blank">
                                    Twitter <br>
                                    <span>Follow us</span>
                                    <i class="bx bxl-twitter"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if ($settings->get_setting('social_instagram_link')): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($settings->get_setting('social_instagram_link')); ?>" target="_blank">
                                    Instagram <br>
                                    <span>Follow us</span>
                                    <i class="bx bxl-instagram"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if ($settings->get_setting('social_youtube_link')): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($settings->get_setting('social_youtube_link')); ?>" target="_blank">
                                    YouTube <br>
                                    <span>Subscribe</span>
                                    <i class="bx bxl-youtube"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</section>
<!-- End Blog Details Area -->

<?php
$page->public_footer();
?>
