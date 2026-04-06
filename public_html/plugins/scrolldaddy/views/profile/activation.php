<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('activation_logic.php', 'logic', 'system', null, 'scrolldaddy'));

$page_vars = process_logic(activation_logic($_GET, $_POST));
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

	if($device->get('sdd_is_active')){

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
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>Your Personal DNS URL</h3>
							<p>This URL is unique to this device. Keep it private — anyone with it can use your filter settings.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
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
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
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
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83" fill="currentColor" stroke="none"/></svg>iPhone &amp; iPad</h3>
							<p>Install a DNS configuration profile — one tap to enable.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/></svg></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Open this page in <strong>Safari</strong> on your device</li>
										<li>Tap <strong>Download Profile</strong> below</li>
										<li>Open <strong>Settings</strong> → tap <strong>Profile Downloaded</strong></li>
										<li>Tap <strong>Install</strong> and follow the prompts</li>
									</ol>
								</div>
							</div>
							<a class="th-btn style5" href="/profile/scrolldaddy/mobileconfig?device_id='.$device->key.'">Download Profile</a>
						</div>
					</div>
				</div>

				<!-- macOS -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83" fill="currentColor" stroke="none"/></svg>Mac</h3>
							<p>Install the same DNS configuration profile used on iPhone and iPad.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/></svg></div>
								<div class="author-info">
									<ol style="padding-left:16px;font-size:13px;line-height:2;">
										<li>Click <strong>Download Profile</strong> below</li>
										<li>Open <strong>System Settings</strong> → <strong>Privacy &amp; Security</strong></li>
										<li>Scroll down and click <strong>Profile: Review Profile...</strong></li>
										<li>Click <strong>Install...</strong> and enter your password</li>
									</ol>
								</div>
							</div>
							<a class="th-btn style5" href="/profile/scrolldaddy/mobileconfig?device_id='.$device->key.'">Download Profile</a>
						</div>
					</div>
				</div>

				<!-- Android -->
				<div class="col-md-6 col-lg-4">
					<div class="job-post style2">
						<div class="job-content">
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M6 18c0 .55.45 1 1 1h1v3.5c0 .83.67 1.5 1.5 1.5s1.5-.67 1.5-1.5V19h2v3.5c0 .83.67 1.5 1.5 1.5s1.5-.67 1.5-1.5V19h1c.55 0 1-.45 1-1V8H6v10zM3.5 8C2.67 8 2 8.67 2 9.5v7c0 .83.67 1.5 1.5 1.5S5 17.33 5 16.5v-7C5 8.67 4.33 8 3.5 8zm17 0c-.83 0-1.5.67-1.5 1.5v7c0 .83.67 1.5 1.5 1.5s1.5-.67 1.5-1.5v-7c0-.83-.67-1.5-1.5-1.5zm-4.97-5.84l1.3-1.3c.2-.2.2-.51 0-.71-.2-.2-.51-.2-.71 0l-1.48 1.48C13.85 1.23 12.95 1 12 1c-.96 0-1.86.23-2.66.63L7.85.15c-.2-.2-.51-.2-.71 0-.2.2-.2.51 0 .71l1.31 1.31C6.97 3.26 6 5.01 6 7h12c0-1.99-.97-3.75-2.47-4.84zM10 5H9V4h1v1zm5 0h-1V4h1v1z" fill="currentColor" stroke="none"/></svg>Android</h3>
							<p>Android uses DNS over TLS. Enter your private hostname in the Private DNS setting.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
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
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801" fill="currentColor" stroke="none"/></svg>Windows</h3>
							<p>Windows 11 supports encrypted DNS natively. Windows 10 users can configure via a browser instead.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
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
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><rect x="2" y="14" width="20" height="6" rx="2"/><path d="M6 14V8"/><path d="M18 14V8"/><path d="M12 14V4"/><path d="M2 8h20"/></svg>Router</h3>
							<p>Configure once on your router to cover every device on your home network automatically.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="2" y="14" width="20" height="6" rx="2"/><path d="M6 14V8"/><path d="M18 14V8"/><path d="M12 14V4"/><path d="M2 8h20"/></svg></div>
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
							<h3 class="box-title"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>Browser Only</h3>
							<p>Configure filtering for a single browser. Covers that browser on any OS.</p>
						</div>
						<div class="job-post_author">
							<div class="job-wrapp">
								<div class="job-author"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div>
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
				<a href="/profile/scrolldaddy/devices" class="th-btn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12,19 5,12 12,5"/></svg>Back To Devices</a>
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
				<a href="/profile/scrolldaddy/devices" class="th-btn"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em;margin-right:0.5rem"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12,19 5,12 12,5"/></svg>Back To Devices</a>
			</div>
		</div>
		</section>';
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
