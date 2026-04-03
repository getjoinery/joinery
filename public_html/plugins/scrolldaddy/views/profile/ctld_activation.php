<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('ctld_activation_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(ctld_activation_logic($_GET, $_POST));
	$tier = $page_vars['tier'];
	$device = $page_vars['device'];
	$user = $page_vars['user'];
	$doh_url = $page_vars['doh_url'];
	$dot_hostname = $page_vars['dot_hostname'];
	$resolver_uid = $page_vars['resolver_uid'];

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile',
		'breadcrumbs' => array(
			'My Profile' => '',
		),
	);
	$page->public_header($hoptions, NULL);

	echo PublicPage::BeginPage('Device Setup', $hoptions);

	if($device->get('cdd_is_active')){

		$device_name = strip_tags($device->get_readable_name());
		$doh_url_safe = htmlspecialchars($doh_url);
		$dot_hostname_safe = htmlspecialchars($dot_hostname);

		echo '
		<section class="space">
		<div class="container">

			<h2 class="sec-title text-center mb-20">Set Up '.htmlspecialchars($device_name).'</h2>
			<p class="text-center mb-50" style="color:#666;">Configure your device to filter DNS through ScrollDaddy. Choose your platform below.</p>

			<!-- DoH URL banner -->
			<div class="row justify-content-center mb-40">
				<div class="col-lg-10">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-regular fa-link me-2"></i>Your Personal DNS URL</h3>
							<p>This URL is unique to this device. Keep it private — anyone with it can use your filter settings.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-shield-check"></i></div>
								<div class="author-info">
									<h3 class="company-name">DNS over HTTPS (DoH) URL</h3>
									<h5 class="price" style="word-break:break-all;font-size:13px;font-family:monospace;">'.$doh_url_safe.'</h5>
								</div>
							</div>
							<button class="th-btn style5" onclick="navigator.clipboard.writeText(\''.addslashes($doh_url).'\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy URL\',2000);">Copy URL</button>
						</div>';

		if($dot_hostname){
			echo '
						<div class="job-post_author" style="margin-top:8px;">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-lock"></i></div>
								<div class="author-info">
									<h3 class="company-name">DNS over TLS (DoT) Hostname</h3>
									<h5 class="price" style="word-break:break-all;font-size:13px;font-family:monospace;">'.$dot_hostname_safe.'</h5>
								</div>
							</div>
							<button class="th-btn style5" onclick="navigator.clipboard.writeText(\''.addslashes($dot_hostname).'\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy\',2000);">Copy</button>
						</div>';
		}

		echo '
					</div>
				</div>
			</div>

			<!-- Platform setup cards -->
			<div class="row justify-content-center gy-4">

				<!-- iOS / iPadOS -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-brands fa-apple me-2"></i>iPhone &amp; iPad</h3>
							<p>Install a DNS configuration profile — one tap to enable.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-file-certificate"></i></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Open this page in <strong>Safari</strong> on your device</li>
										<li>Tap <strong>Download Profile</strong> below</li>
										<li>Open <strong>Settings</strong> → tap <strong>Profile Downloaded</strong></li>
										<li>Tap <strong>Install</strong> and follow the prompts</li>
									</ol>
								</div>
							</div>
							<a class="th-btn style5" href="/profile/ctld_mobileconfig?device_id='.$device->key.'">Download Profile</a>
						</div>
					</div>
				</div>

				<!-- macOS -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-brands fa-apple me-2"></i>Mac</h3>
							<p>Install the same DNS configuration profile used on iPhone and iPad.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-file-certificate"></i></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Click <strong>Download Profile</strong> below</li>
										<li>Open <strong>System Settings</strong> → <strong>Privacy &amp; Security</strong></li>
										<li>Scroll down and click <strong>Profile: Review Profile...</strong></li>
										<li>Click <strong>Install...</strong> and enter your password</li>
									</ol>
								</div>
							</div>
							<a class="th-btn style5" href="/profile/ctld_mobileconfig?device_id='.$device->key.'">Download Profile</a>
						</div>
					</div>
				</div>

				<!-- Android -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-brands fa-android me-2"></i>Android</h3>
							<p>Android uses DNS over TLS. Enter your private hostname in the Private DNS setting.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-lock"></i></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Open <strong>Settings</strong> → <strong>Network &amp; Internet</strong></li>
										<li>Tap <strong>Private DNS</strong></li>
										<li>Select <strong>Private DNS provider hostname</strong></li>
										<li>Paste your DoT hostname below and tap <strong>Save</strong></li>
									</ol>';

		if($dot_hostname){
			echo '
									<p style="font-size:12px;font-family:monospace;word-break:break-all;background:#f5f5f5;padding:6px 8px;border-radius:4px;margin-top:8px;">'.$dot_hostname_safe.'</p>';
		} else {
			echo '
									<p style="font-size:12px;color:#999;margin-top:8px;">DoT hostname not yet configured. Contact support.</p>';
		}

		echo '
								</div>
							</div>';

		if($dot_hostname){
			echo '
							<button class="th-btn style5" onclick="navigator.clipboard.writeText(\''.addslashes($dot_hostname).'\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy Hostname\',2000);">Copy Hostname</button>';
		}

		echo '
						</div>
					</div>
				</div>

				<!-- Windows -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-brands fa-windows me-2"></i>Windows</h3>
							<p>Windows 11 supports encrypted DNS natively. Windows 10 users can configure via a browser instead.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-gear"></i></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Open <strong>Settings</strong> → <strong>Network &amp; Internet</strong></li>
										<li>Click your connection (Wi-Fi or Ethernet) → <strong>Hardware properties</strong></li>
										<li>Click <strong>Edit</strong> next to DNS server assignment → set to <strong>Manual</strong></li>
										<li>Enter DNS IP: <strong>45.56.103.84</strong></li>
										<li>Set <em>Preferred DNS encryption</em> to <strong>Encrypted only (HTTPS)</strong></li>
										<li>Enter your DoH URL as the HTTPS template</li>
									</ol>
								</div>
							</div>
							<button class="th-btn style5" onclick="navigator.clipboard.writeText(\''.addslashes($doh_url).'\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy DoH URL\',2000);">Copy DoH URL</button>
						</div>
					</div>
				</div>

				<!-- Router -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-regular fa-router me-2"></i>Router</h3>
							<p>Configure once on your router to cover every device on your home network automatically.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-network-wired"></i></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Log in to your router admin panel (usually <strong>192.168.1.1</strong>)</li>
										<li>Find the <strong>DNS</strong> or <strong>WAN</strong> settings</li>
										<li>Look for <strong>DNS over HTTPS</strong> or <strong>Secure DNS</strong></li>
										<li>Paste your DoH URL and save</li>
									</ol>
									<p style="font-size:12px;color:#999;margin-top:4px;">Supported on Asus, pfSense, OPNsense, Firewalla, and others. Steps vary by model.</p>
								</div>
							</div>
							<button class="th-btn style5" onclick="navigator.clipboard.writeText(\''.addslashes($doh_url).'\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy DoH URL\',2000);">Copy DoH URL</button>
						</div>
					</div>
				</div>

				<!-- Browser -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><i class="fa-regular fa-browser me-2"></i>Browser Only</h3>
							<p>Configure filtering for a single browser. Covers that browser on any OS.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><i class="fa-regular fa-globe"></i></div>
								<div class="author-info">
									<p style="font-size:13px;font-weight:600;margin-bottom:4px;">Chrome / Edge / Brave</p>
									<p style="font-size:12px;margin-bottom:8px;">Settings → Privacy &amp; Security → Security → Use secure DNS → With a custom provider → paste DoH URL</p>
									<p style="font-size:13px;font-weight:600;margin-bottom:4px;">Firefox</p>
									<p style="font-size:12px;">Settings → Privacy &amp; Security → DNS over HTTPS → Max Protection → Custom → paste DoH URL</p>
								</div>
							</div>
							<button class="th-btn style5" onclick="navigator.clipboard.writeText(\''.addslashes($doh_url).'\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy DoH URL\',2000);">Copy DoH URL</button>
						</div>
					</div>
				</div>

			</div><!-- end row -->

			<div class="col-12 text-center mt-40">
				<a href="/profile/devices" class="th-btn"><i class="fal fa-arrow-left me-2"></i>Back To Devices</a>
			</div>

		</div>
		</section>';

	}
	else{
		echo '
		<section class="space">
		<div class="container">
			<div class="error-content text-center">
				<h2 class="error-title">Device Not Active</h2>
				<p class="error-text">This device is not yet active. Please return to your devices list.</p>
				<a href="/profile/devices" class="th-btn"><i class="fal fa-arrow-left me-2"></i>Back To Devices</a>
			</div>
		</div>
		</section>';
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
