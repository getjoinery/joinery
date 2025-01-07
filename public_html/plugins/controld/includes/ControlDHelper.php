<?php
//require_once('Globalvars.php');
//require_once('SessionControl.php');

class ControlDHelper{
	
	private $api_key;
	private $debug;
	public $test_mode;
	
	public static $profile_options = array (
		'shared' => 'Share profile with sub organizations',
		'ai_malware' => 'AI Malware Filter',
		'safesearch' => 'Safe search',
		'safeyoutube' => 'Block mature content and comments',
		'block_rfc1918' => 'DNS Rebind Protection',
		'no_dnssec' => 'Turn off DNSSEC validation for compatibility.',
		'ttl_blck' => 'DNS record TTL (in seconds) when blocking',
		'ttl_spff' => 'DNS record TTL (in seconds) when redirecting',
		'ttl_pass' => 'DNS record TTL (in seconds) when bypassing',
		'b_resp' => 'How to respond to blocked queries', // [0] => 0.0.0.0 [3] => NXDOMAIN [5] => REFUSED [7] => Custom [9] => Branded
		'spoof_ipv6' => 'Cross-stack compatibility mode for IPv6 enabled networks',
		'dns64' => 'Enables DNS64 on NAT64 supporting IPv6-only networks',
	);
	public static $filters = array(
		'ads_small' => 'Ads & Trackers (Relaxed)',
		'ads_medium' => 'Ads & Trackers (Balanced)',
		'ads' => 'Ads & Trackers (Strict)',
		'porn' => 'Adult content',
		'porn_strict' => 'Adult content (Strict)',
		'noai' => 'Artificial intelligence',
		'fakenews' => 'Hoaxes and disinformation',
		'cryptominers' => 'Cryptocurrency',
		'dating' => 'Dating sites',
		'drugs' => 'Illegal drugs',
		'ddns' => 'Dynamic DNS hosts',
		'filehost' => 'File hosting',
		'gambling' => 'Gambling sites',
		'games' => 'Games',
		'gov' => 'Government sites',
		'iot' => 'Internet of things',
		'malware' => 'Known malware sites (Relaxed)',
		'ip_malware' => 'Known malware sites (Balanced)',
		'ai_malware' => 'Known malware sites (Strict)',
		'nrd_small' => 'New domains (Last week)',
		'nrd' => 'New domains (Last month)',
		'typo' => 'Phishing domains',
		'social' => 'Social media',
		'torrents' => 'Torrent sites',
		'urlshort' => 'URL shorteners',
		'dnsvpn' => 'VPN and DNS providers',
	);
	
	public static $service_categories = array(
		'audio' => 'Audio streaming',
		'career' => 'Career and education',
		'finance' => 'Finance and crypto',
		'gaming' => 'Gaming services',
		'hosting' => 'Web hosting',
		'news' => 'News sites',
		'recreation' => 'Travel and recreation sites',
		'shop' => 'Shopping and auction sites',
		'social' => 'Social networks',
		'tools' => 'Business tools',
		'vendors' => 'IOT vendors',
		'video' => 'Video streaming services',
	);
	

	public static $services = array(
		'audio' => array (
		    "applemusic" => "Apple Music",
			"audacy" => "Audacy",
			"audible" => "Audible",
			"deezer" => "Deezer",
			"gaana" => "Gaana",
			"joox" => "JOOX",
			"lastfm" => "Last.fm",
			"napster" => "Napster",
			"pandora" => "Pandora",
			"shazam" => "Shazam",
			"siriusxm" => "SiriusXM",
			"sonos" => "Sonos",
			"soundcloud" => "Soundcloud",
			"spotify" => "Spotify",
			"tidal" => "Tidal",
			"tunein" => "TuneIn"
		),
		'career' => array (
			"byjus" => "Byjus",
			"clever" => "Clever",
			"codecademy" => "Codecademy",
			"coursera" => "Coursera",
			"docebo" => "Docebo",
			"duolingo" => "Duolingo",
			"fiverr" => "Fiverr",
			"freelancer" => "Freelancer",
			"glassdoor" => "Glassdoor",
			"guru" => "Guru",
			"indeed" => "Indeed",
			"instructure" => "Instructure",
			"khanacademy" => "Khan Academy",
			"peopleperhour" => "PeoplePerHour",
			"pluralsight" => "Pluralsight",
			"scribd" => "Scribd",
			"skillshare" => "Skillshare",
			"toptal" => "Toptal",
			"udacity" => "Udacity",
			"upwork" => "Upwork",
			"ziprecruiter" => "ZipRecruiter"
		),
		'finance' => array(
			"adp" => "ADP",
			"anz" => "ANZ",
			"absa" => "Absa",
			"afterpay" => "Afterpay",
			"alipay" => "Alipay",
			"americanexpress" => "American Express",
			"argenta" => "Argenta",
			"authorizenet" => "Authorize.net",
			"bmo" => "BMO",
			"bnpparibas" => "BNP Paribas",
			"bnymellon" => "BNY Mellon",
			"banamex" => "Banamex",
			"bancosantander" => "Banco Santander",
			"bankofamerica" => "Bank of America",
			"banorte" => "Banorte",
			"barclays" => "Barclays",
			"belfius" => "Belfius",
			"billcom" => "Bill.com",
			"binance" => "Binance",
			"bitcoin" => "Bitcoin",
			"blackrock" => "BlackRock",
			"blackbaud" => "Blackbaud",
			"braintree" => "Braintree",
			"cibc" => "CIBC",
			"canadiantirebank" => "Canadian Tire Bank",
			"capitalone" => "Capital One",
			"cardconnect" => "CardConnect",
			"schwab" => "Charles Schwab",
			"cigna" => "Cigna",
			"citi" => "Citi",
			"coinbase" => "Coinbase",
			"commbank" => "CommBank",
			"cybersource" => "CyberSource",
			"discovercard" => "Discover Card",
			"dogecoin" => "Dogecoin",
			"ey" => "EY Ernst & Young",
			"elevancehealth" => "Elevance Health",
			"equifax" => "Equifax",
			"ethereum" => "Ethereum",
			"ethermine" => "Ethermine",
			"experian" => "Experian",
			"fnb" => "FNB",
			"fidelity" => "Fidelity",
			"firstrand" => "FirstRand",
			"fiserv" => "Fiserv",
			"flypoolbeam" => "Flypool BEAM",
			"flypoolravencoin" => "Flypool Ravencoin",
			"flypoolycash" => "Flypool Ycash",
			"flypoolzcash" => "Flypool Zcash",
			"freshbooks" => "FreshBooks",
			"geico" => "Geico",
			"hsbc" => "HSBC",
			"hellobank" => "Hello bank",
			"ihsmarkit" => "IHS Markit",
			"ing" => "ING",
			"insigniafinancial" => "Insignia Financial",
			"interactivebrokers" => "Interactive Brokers",
			"intuit" => "Intuit",
			"investec" => "Investec",
			"jcb" => "JCB",
			"jpmorganchase" => "JPMorgan Chase",
			"kakaopay" => "KakaoPay",
			"lendingtree" => "LendingTree",
			"lloydsbank" => "Lloyds Bank",
			"mastercard" => "Mastercard",
			"merrill" => "Merrill",
			"moneris" => "Moneris",
			"nationalaustraliabank" => "National Australia Bank",
			"nationalbankofcanada" => "National Bank of Canada",
			"navyfederal" => "Navy Federal",
			"nedbank" => "Nedbank",
			"patriotsoftware" => "Patriot Software",
			"paypal" => "PayPal",
			"paytm" => "Paytm",
			"phonepe" => "PhonePe",
			"prudential" => "Prudential",
			"q4investorrelations" => "Q4 Investor Relations",
			"rbcroyalbank" => "RBC Royal Bank",
			"rbs" => "RBS",
			"receiptbank" => "Receipt Bank",
			"refinitiv" => "Refinitiv",
			"revolut" => "Revolut",
			"robinhood" => "Robinhood",
			"scotiabank" => "Scotiabank",
			"siacoin" => "Siacoin",
			"simplemining" => "SimpleMining",
			"square" => "Square",
			"standardbank" => "Standard Bank",
			"stripe" => "Stripe",
			"suncorpgroup" => "Suncorp Group",
			"td" => "TD Bank",
			"transunion" => "TransUnion",
			"ubs" => "UBS",
			"venmo" => "Venmo",
			"visa" => "Visa",
			"wepay" => "WePay",
			"wealthsimple" => "Wealthsimple",
			"wellsfargo" => "Wells Fargo",
			"westernunion" => "Western Union",
			"westpac" => "Westpac",
			"wise" => "Wise",
			"wolterskluwer" => "Wolters Kluwer",
			"worldline" => "Worldline",
			"xero" => "Xero",
			"yahoofinance" => "Yahoo Finance",
			"yodlee" => "Yodlee",
			"zillow" => "Zillow"			
	
		),
		'gaming' => array(
			"2k" => "2K Gaming",
			"activision" => "Activision",
			"bandai" => "Bandai",
			"blizzard" => "Battle.net",
			"brawlstars" => "Brawl Stars",
			"candycrush" => "Candy Crush",
			"clashofclans" => "Clash of Clans",
			"ea" => "EA Origin",
			"epicgames" => "Epic Games",
			"fortnite" => "Fortnite",
			"geforcenow" => "GeForce Now",
			"lol" => "League of Legends",
			"minecraft" => "Minecraft",
			"niantic" => "Niantic",
			"nintendo" => "Nintendo",
			"pubg" => "PUBG",
			"playstation" => "Playstation",
			"riotgames" => "Riot Games",
			"roblox" => "Roblox",
			"rockstargames" => "Rockstar Games",
			"runescape" => "RuneScape",
			"steam" => "Steam",
			"twitch" => "Twitch",
			"ubisoft" => "Ubisoft",
			"wargaming" => "Wargaming",
			"xbox" => "Xbox",
			"zynga" => "Zynga"		
		),
		'hosting' => array(
			"amp" => "AMP Project",
			"akami" => "Akamai",
			"alibabacloud" => "Alibaba Cloud",
			"aws" => "Amazon AWS",
			"anexia" => "Anexia",
			"aofei" => "Aofei",
			"aptum" => "Aptum",
			"azure" => "Azure",
			"baiduai" => "Baidu Cloud",
			"baishancloud" => "BaishanCloud",
			"bluehost" => "Bluehost",
			"bunnynet" => "Bunny.net",
			"byteplus" => "BytePlus",
			"cdn77" => "CDN77",
			"cachefly" => "CacheFly",
			"chinatelecom" => "China Telecom",
			"cloudflare" => "CloudFlare",
			"cloudinary" => "Cloudinary",
			"cloudways" => "Cloudways",
			"clouvider" => "Clouvider",
			"cogent" => "Cogent",
			"datacamp" => "DataCamp",
			"digitalocean" => "DigitalOcean",
			"dreamhost" => "DreamHost",
			"edgenext" => "EdgeNext",
			"edgeuno" => "EdgeUno",
			"edgio" => "Edgio / Edgecast",
			"equinix" => "Equinix",
			"fastly" => "Fastly",
			"flexential" => "Flexential",
			"flyio" => "Fly.io",
			"gcorelabs" => "G-Core Labs",
			"godaddy" => "GoDaddy",
			"googlecloud" => "Google Cloud",
			"heroku" => "Heroku",
			"hetzner" => "Hetzner",
			"hostgator" => "HostGator",
			"huaweicloud" => "Huawei Cloud",
			"hurricaneelectric" => "Hurricane Electric",
			"ibmcloud" => "IBM Cloud",
			"inap" => "INAP",
			"kaopu" => "Kaopu Cloud",
			"keycdn" => "KeyCDN",
			"kingsoftcloud" => "Kingsoft Cloud",
			"latitudesh" => "Latitude.sh",
			"leaseweb" => "Leaseweb",
			"linode" => "Linode",
			"lumen" => "Lumen",
			"m247" => "M247",
			"macquarie" => "Macquarie Telecom",
			"netactuate" => "NetActuate",
			"netlify" => "Netlify Hosting",
			"ovh" => "OVHcloud",
			"pantheon" => "Pantheon",
			"rackspace" => "Rackspace",
			"render" => "Render",
			"scaleway" => "Scaleway",
			"squarespace" => "Squarespace",
			"stackpath" => "StackPath",
			"switch" => "Switch",
			"tata" => "Tata Communications",
			"tencentcloud" => "Tencent Cloud",
			"unitasglobal" => "Unitas Global",
			"vercel" => "Vercel",
			"volcengine" => "Volcengine",
			"vultr" => "Vultr",
			"wpengine" => "WP Engine",
			"wangsu" => "Wangsu",
			"weebly" => "Weebly",
			"wix" => "Wix",
			"wordpress" => "WordPress",
			"yext" => "Yext",
			"zayo" => "Zayo",
			"zenlayer" => "Zenlayer",
			"i3d" => "i3D.net"
	
		),
		'news' => array(
			"abcnews" => "ABC News",
			"accuweather" => "AccuWeather",
			"arstechnica" => "Ars Technica",
			"axios" => "Axios",
			"bleacherreport" => "Bleacher Report",
			"bloomberg" => "Bloomberg",
			"businessjournals" => "Business Journals",
			"buzzfeed" => "BuzzFeed",
			"cnbc" => "CNBC",
			"cnn" => "CNN",
			"dailymail" => "Daily Mail",
			"derspiegel" => "Der Spiegel",
			"fivethirtyeight" => "FiveThirtyEight",
			"forbes" => "Forbes",
			"fortune" => "Fortune",
			"foxnews" => "Fox News",
			"gannett" => "Gannett",
			"theguardian" => "Guardian News",
			"huffpost" => "HuffPost",
			"inshorts" => "Inshorts",
			"nbcnews" => "NBC News",
			"newyorkpost" => "New York Post",
			"realclearpolitics" => "RealClearPolitics",
			"reuters" => "Reuters",
			"skynews" => "Sky News",
			"slashdot" => "SlashDot",
			"sydneymorningherald" => "Sydney Morning Herald",
			"tmz" => "TMZ",
			"theatlantic" => "The Atlantic",
			"theeconomist" => "The Economist",
			"theglobeandmail" => "The Globe and Mail",
			"thenewyorktimes" => "The New York Times",
			"theregister" => "The Register",
			"thetimesofindia" => "The Times of India",
			"theweatherchannel" => "The Weather Channel",
			"theweathernetwork" => "The Weather Network",
			"tribunepublishing" => "Tribune Publishing",
			"udn" => "UDN",
			"usatoday" => "USA Today",
			"vice" => "Vice",
			"vox" => "Vox",
			"wallstreetjournal" => "Wall Street Journal",
			"washingtonpost" => "Washington Post",
			"weatherunderground" => "Weather Underground",
			"weatherflow" => "WeatherFlow"
		),
		'recreation' => array(
			"airbnb" => "Airbnb",
			"booking" => "Booking",
			"eventbrite" => "Eventbrite",
			"expedia" => "Expedia",
			"hilton" => "Hilton",
			"homestay" => "Homestay",
			"hyatt" => "Hyatt",
			"kayak" => "Kayak",
			"marriott" => "Marriott",
			"opentable" => "OpenTable",
			"sonder" => "Sonder",
			"ticketmaster" => "Ticketmaster",
			"tripadvisor" => "Tripadvisor",
			"vrbo" => "Vrbo",
			"wyndhamhotels" => "Wyndham",
			"yelp" => "Yelp"
		),
		'shop' => array(
			"aliexpress" => "AliExpress",
			"alibaba" => "Alibaba",
			"amazon" => "Amazon",
			"autotrader" => "AutoTrader",
			"bestbuy" => "Best Buy",
			"bigcommerce" => "BigCommerce",
			"canadiantire" => "Canadian Tire",
			"chownow" => "Chownow",
			"costco" => "Costco",
			"craigslist" => "Craigslist",
			"ebay" => "EBay",
			"etsy" => "Etsy",
			"gumtree" => "Gumtree",
			"homedepot" => "Home Depot",
			"ikea" => "IKEA",
			"instacart" => "Instacart",
			"kijiji" => "Kijiji",
			"kohls" => "Kohl's",
			"kroger" => "Kroger",
			"lazada" => "Lazada",
			"lululemon" => "Lululemon",
			"macys" => "Macy's",
			"mercadolibre" => "Mercado Libre",
			"rakuten" => "Rakuten",
			"shein" => "SHEIN",
			"shopee" => "Shopee",
			"shopify" => "Shopify",
			"taobao" => "Taobao",
			"target" => "Target",
			"temu" => "Temu",
			"tmall" => "Tmall",
			"tokopedia" => "Tokopedia",
			"walmart" => "Walmart",
			"wayfair" => "Wayfair",
			"wish" => "Wish",
			"zalando" => "Zalando"		
		),
		'social' => array(
			"4chan" => "4chan",
			"9gag" => "9GAG",
			"badoo" => "Badoo",
			"bilibili" => "Bilibili",
			"bluesky" => "Bluesky",
			"bumble" => "Bumble",
			"clubhouse" => "Clubhouse",
			"deviantart" => "DeviantArt",
			"discord" => "Discord",
			"douyin" => "Douyin",
			"element" => "Element",
			"facebook" => "Facebook",
			"fediverse" => "Fediverse",
			"flickr" => "Flickr",
			"gmx" => "GMX",
			"gab" => "Gab",
			"gmail" => "Gmail",
			"gravatar" => "Gravatar",
			"imgur" => "Imgur",
			"instagram" => "Instagram",
			"kik" => "Kik",
			"kwai" => "Kwai",
			"line" => "Line Messenger",
			"linkedin" => "LinkedIn",
			"mastodon" => "Mastodon",
			"messenger" => "Messenger",
			"msoutlook" => "Microsoft Outlook",
			"mutual" => "Mutual LDS Dating",
			"nextdoor" => "Nextdoor",
			"okcupid" => "OkCupid",
			"pairs" => "Pairs dating",
			"parler" => "Parler",
			"pinterest" => "Pinterest",
			"protonmail" => "ProtonMail",
			"reddit" => "Reddit",
			"signal" => "Signal",
			"slack" => "Slack",
			"snapchat" => "Snapchat",
			"teamspeak" => "TeamSpeak",
			"telegram" => "Telegram",
			"threads" => "Threads",
			"tiktok" => "TikTok",
			"tinder" => "Tinder",
			"truthsocial" => "Truth Social",
			"tumblr" => "Tumblr",
			"vk" => "VK",
			"viber" => "Viber",
			"wechat" => "WeChat",
			"whatsapp" => "WhatsApp",
			"twitter" => "X Twitter",
			"xiaohongshu" => "Xiaohongshu",
			"yahoomail" => "Yahoo Mail"
		),
		'tools' => array(
			"1password" => "1Password",
			"algolia" => "Algolia",
			"anydesk" => "AnyDesk",
			"apple" => "Apple",
			"asana" => "Asana",
			"atlassian" => "Atlassian",
			"backblaze" => "Backblaze",
			"behance" => "Behance",
			"bing" => "Bing",
			"box" => "Box",
			"brave" => "Brave Browser",
			"bugsnag" => "Bugsnag",
			"canva" => "Canva",
			"chatgpt" => "ChatGPT",
			"claude" => "Claude",
			"crashlytics" => "Crashlytics",
			"docusign" => "DocuSign",
			"dropbox" => "Dropbox",
			"duckduckgo" => "DuckDuckGo",
			"evernote" => "Evernote",
			"fedex" => "FedEx",
			"figma" => "Figma",
			"firefox" => "Firefox",
			"foxit" => "Foxit",
			"gemini" => "Gemini",
			"github" => "Github",
			"google" => "Google",
			"grafana" => "Grafana",
			"grammarly" => "Grammarly",
			"lastpass" => "LastPass",
			"letsencrypt" => "Let's Encrypt",
			"liveperson" => "LivePerson",
			"logmein" => "LogMeIn",
			"mega" => "MEGA",
			"mailchimp" => "Mailchimp",
			"mailgun" => "Mailgun",
			"mediafire" => "MediaFire",
			"notion" => "Notion",
			"onedrive" => "OneDrive",
			"openai" => "OpenAI",
			"openoffice" => "OpenOffice",
			"opera" => "Opera Browser",
			"pagerduty" => "PagerDuty",
			"parallels" => "Parallels",
			"quora" => "Quora",
			"qwant" => "Qwant",
			"remotepc" => "RemotePC",
			"salesforce" => "Salesforce",
			"sendgrid" => "SendGrid",
			"sharefile" => "ShareFile",
			"skype" => "Skype",
			"splashtop" => "Splashtop",
			"stackoverflow" => "Stack Overflow",
			"stackexchange" => "StackExchange",
			"startpage" => "Startpage",
			"teamviewer" => "TeamViewer",
			"troubleshooter" => "Troubleshooter",
			"ups" => "UPS",
			"uber" => "Uber",
			"wetransfer" => "WeTransfer",
			"webex" => "WebEx",
			"webflow" => "Webflow",
			"wikipedia" => "Wikipedia",
			"yahoo" => "Yahoo",
			"yandex" => "Yandex",
			"zendesk" => "Zendesk",
			"zoho" => "Zoho",
			"zoom" => "Zoom",
			"tuta" => "tuta"		
		),
		'vendors' => array(
			"apc" => "APC",
			"asus" => "ASUS",
			"asustor" => "ASUSTOR",
			"avg" => "AVG",
			"acronis" => "Acronis",
			"adobe" => "Adobe",
			"amazondevices" => "Amazon Devices",
			"arcadyan" => "Arcadyan",
			"arlo" => "Arlo",
			"aruba" => "Aruba",
			"augusthome" => "August Home",
			"autodesk" => "Autodesk",
			"avast" => "Avast",
			"avira" => "Avira",
			"barracuda" => "Barracuda",
			"belkin" => "Belkin",
			"benq" => "BenQ",
			"bitdefender" => "Bitdefender",
			"bose" => "Bose",
			"brightcove" => "Brightcove",
			"brother" => "Brother",
			"buffalotechnology" => "Buffalo Technology",
			"canon" => "Canon",
			"checkpoint" => "Check Point",
			"cisco" => "Cisco",
			"ciscomeraki" => "Cisco Meraki",
			"clearphone" => "ClearPHONE",
			"commscope" => "CommScope",
			"comodo" => "Comodo",
			"corel" => "Corel",
			"crestron" => "Crestron",
			"crowdstrike" => "CrowdStrike",
			"cylance" => "Cylance",
			"dlink" => "D-Link",
			"dahua" => "Dahua",
			"dell" => "Dell",
			"digicert" => "DigiCert",
			"eset" => "ESET",
			"ecobee" => "Ecobee",
			"eero" => "Eero",
			"epson" => "Epson",
			"espressif" => "Espressif",
			"eufy" => "Eufy",
			"ezviz" => "Ezviz",
			"fsecure" => "F-Secure",
			"flirsystems" => "FLIR Systems",
			"fitbit" => "Fitbit",
			"fortinet" => "Fortinet",
			"freeip" => "FreeIP",
			"freshworks" => "Freshworks",
			"glinet" => "GL.iNet",
			"garmin" => "Garmin",
			"gopro" => "GoPro",
			"hp" => "HP Hewlett-Packard",
			"hikvision" => "Hikvision",
			"honeywell" => "Honeywell",
			"huntress" => "Huntress",
			"ibm" => "IBM",
			"imperva" => "Imperva",
			"intel" => "Intel",
			"java" => "Java",
			"jetbrains" => "JetBrains",
			"junipernetworks" => "Juniper Networks",
			"kaspersky" => "Kaspersky",
			"kindle" => "Kindle",
			"lg" => "LG Electronics",
			"lgsmarttv" => "LG Smart TV",
			"lifx" => "LIFX",
			"lenovo" => "Lenovo",
			"lexmark" => "Lexmark",
			"linksys" => "Linksys",
			"logitech" => "Logitech",
			"lorex" => "Lorex",
			"malwarebytes" => "Malwarebytes",
			"mcafee" => "McAfee",
			"meizu" => "Meizu",
			"meross" => "Meross",
			"miwifi" => "MiWiFi",
			"microsoft" => "Microsoft",
			"microtik" => "MikroTik",
			"mitsubishielectric" => "Mitsubishi Electric",
			"motorola" => "Motorola",
			"nest" => "Nest",
			"netsuite" => "NetSuite",
			"netatmo" => "Netatmo",
			"netgear" => "Netgear",
			"nightowl" => "Night Owl",
			"nimblestorage" => "Nimble Storage",
			"nokia" => "Nokia",
			"norton" => "Norton",
			"nvidia" => "Nvidia",
			"oppo" => "OPPO",
			"oculus" => "Oculus",
			"okta" => "Okta",
			"oneplus" => "OnePlus",
			"oracle" => "Oracle",
			"paloaltonetworks" => "Palo Alto Networks",
			"pandasecurity" => "Panda Security",
			"peplink" => "Peplink",
			"philips" => "Philips",
			"powerley" => "Powerley",
			"proofpoint" => "Proofpoint",
			"pulseway" => "Pulseway",
			"qnapsystems" => "QNAP Systems",
			"qihoo360" => "Qihoo 360",
			"qualcomm" => "Qualcomm",
			"raspberrypi" => "Raspberry Pi",
			"resideo" => "Resideo",
			"ring" => "Ring",
			"roku" => "Roku",
			"ruckusnetworks" => "Ruckus Networks",
			"sap" => "SAP",
			"sagemcom" => "Sagemcom",
			"salientsystems" => "Salient Systems",
			"samsung" => "Samsung",
			"sectigo" => "Sectigo",
			"sentinelone" => "SentinelOne",
			"sercomm" => "Sercomm",
			"silicondust" => "SiliconDust",
			"smartthings" => "SmartThings",
			"snowflake" => "Snowflake",
			"solarwinds" => "SolarWinds",
			"sony" => "Sony",
			"sonytv" => "Sony TV",
			"sophos" => "Sophos",
			"splunk" => "Splunk",
			"symantec" => "Symantec",
			"syncro" => "Syncro",
			"synology" => "Synology",
			"tplink" => "TP-Link",
			"tecnomobile" => "Tecno Mobile",
			"tenda" => "Tenda",
			"tesla" => "Tesla",
			"tivo" => "Tivo",
			"trellix" => "Trellix",
			"trendmicro" => "Trend Micro",
			"tuyasmart" => "Tuya Smart",
			"ubiquiti" => "Ubiquiti",
			"uniview" => "Uniview",
			"vizio" => "VIZIO",
			"veea" => "Veea",
			"verkada" => "Verkada",
			"vivo" => "Vivo",
			"webroot" => "Webroot",
			"withings" => "Withings",
			"wyze" => "Wyze",
			"xiaomi" => "Xiaomi",
			"xiongmai" => "Xiongmai",
			"zte" => "ZTE",
			"zyxel" => "Zyxel",
			"irobot" => "iRobot",
			"ixsystems" => "iXsystems"		
		),
		'video' => array(
			"10play" => "10play",
			"6play" => "6play",
			"7plus" => "7plus",
			"9now" => "9Now",
			"aetv" => "A&E TV",
			"abc" => "ABC",
			"abciview" => "ABC iview",
			"adsports" => "AD Sports",
			"aisplay" => "AIS Play",
			"amcplus" => "AMC+",
			"ard" => "ARD Mediathek",
			"arte" => "ARTE",
			"att" => "AT&T TV",
			"abema" => "Abema",
			"acorntv" => "Acorn TV",
			"ahavideo" => "Aha Video",
			"allente" => "Allente",
			"altbalaji" => "Alt Balaji",
			"animelab" => "Animelab",
			"antenna" => "Antenna",
			"appletv" => "Apple TV Channels",
			"arenacloud" => "Arena Cloud",
			"atresplayer" => "Atresplayer",
			"iplayer" => "BBC iPlayer",
			"bnt" => "BNT",
			"btsports" => "BT Sports",
			"beinsports" => "BeIN Sports",
			"binge" => "Binge",
			"blutv" => "BluTV",
			"bluetv" => "BlueTV",
			"boomerang" => "Boomerang TV",
			"britbox" => "Britbox",
			"cbc" => "CBC",
			"csporttv" => "CSPORT TV",
			"ctv" => "CTV",
			"cw" => "CW TV",
			"canalrcn" => "Canal RCN",
			"canalplus" => "Canal+",
			"caracoltv" => "Caracol TV",
			"cartoonnetwork" => "Cartoon Network",
			"ceskatelevize" => "Ceska Televize",
			"channel4" => "Channel4",
			"charge" => "Charge",
			"citytv" => "Citytv",
			"clarovideo" => "Clarovideo",
			"comedycentral" => "Comedy Central",
			"cosmote" => "Cosmote",
			"crackle" => "Crackle",
			"crave" => "Crave",
			"criterionchannel" => "Criterion Channel",
			"crunchyroll" => "Crunchyroll",
			"directv" => "DIRECTV",
			"dr" => "DR DK",
			"dstv" => "DStv",
			"dailymotion" => "Dailymotion",
			"dazn" => "Dazn",
			"digisport" => "Digi Sport",
			"dimsum" => "Dimsum",
			"directvgo" => "DirecTV GO",
			"discoveryplus" => "Discovery+",
			"disney" => "Disney Plus",
			"docplay" => "DocPlay",
			"dokitv" => "DokiTV",
			"eontv" => "EON",
			"epix" => "EPIX / MGM+",
			"err" => "ERR TV",
			"espn" => "ESPN+",
			"elevensports" => "Eleven Sports",
			"ertflix" => "Ertflix",
			"eurosportplayer" => "Eurosport Player",
			"euskaditv" => "Euskadi TV",
			"exxen" => "Exxen",
			"fitetv" => "FITE",
			"fx" => "FX Networks",
			"fanatiz" => "Fanatiz",
			"fandangonow" => "FandangoNOW",
			"flowsports" => "Flow Sports",
			"foodnetwork" => "Food Network",
			"f1" => "Formula 1 (F1)",
			"foxnation" => "Fox Nation",
			"fox" => "Fox Now",
			"foxtel" => "Foxtel",
			"francetv" => "France TV",
			"imdbtv" => "Freevee",
			"frndlytv" => "Frndly TV",
			"fubo" => "Fubo TV",
			"funimation" => "Funimation",
			"gcn" => "GCN+",
			"gxrworld" => "GXR World",
			"globaltv" => "Global TV",
			"globoplay" => "Globoplay",
			"goplay" => "GoPlay",
			"hbogo" => "HBO Go",
			"hbomax" => "HBO Max",
			"hrt" => "HRT",
			"hami" => "Hami",
			"hidive" => "HiDive",
			"hoichoitv" => "Hoichoi TV",
			"hotstar" => "Hotstar",
			"hulu" => "Hulu",
			"itv" => "ITV",
			"joyn" => "Joyn",
			"kijk" => "KIJK",
			"kocowa" => "KOCOWA",
			"kapang" => "Kapang",
			"kayosports" => "Kayo Sports",
			"kinopoisk" => "Kinopoisk",
			"looxtv" => "LOOX TV",
			"lrt" => "LRT",
			"ltv" => "LTV",
			"lasestrellas" => "Las Estrellas",
			"lifetimemovieclub" => "Lifetime Movie Club",
			"lionsgate" => "Lionsgate",
			"livenettv" => "Live NetTV",
			"localnow" => "Local Now",
			"megogo" => "MEGOGO",
			"mlb" => "MLB",
			"mubi" => "MUBI",
			"mxplayer" => "MXPlayer",
			"masterstournament" => "Masters Tournament",
			"matchru" => "Match Ru",
			"mewatch" => "MeWatch",
			"mediaklikk" => "Mediaklikk",
			"mediaset" => "Mediaset Play",
			"mitele" => "Mitele",
			"molatv" => "Mola",
			"molotovtv" => "Molotov TV",
			"moretv" => "More TV",
			"moviesanywhere" => "Movies Anywhere",
			"movistar" => "Movistar",
			"my5" => "My5",
			"mytvsuper" => "MyTV Super",
			"myvideo" => "Myvideo",
			"nba" => "NBA",
			"nbc" => "NBC",
			"ncplusgo" => "NC+",
			"ncaa" => "NCAA",
			"nfl" => "NFL",
			"nhl" => "NHL",
			"nlziet" => "NLZIET",
			"npostart" => "NPO Start",
			"nrk" => "NRK TV",
			"netflix" => "Netflix",
			"newfaithnetwork" => "New Faith Network",
			"noggin" => "Noggin",
			"nordiskfilmplus" => "Nordisk Film+",
			"nosey" => "Nosey",
			"novaplus" => "Nova Plus",
			"nowtv" => "NowTV",
			"nowe" => "Nowe",
			"nowotv" => "Nowotv",
			"ocs" => "OCS",
			"orf" => "ORF ON",
			"osnplus" => "OSN Plus",
			"okko" => "Okko TV",
			"onefootball" => "Onefootball",
			"opentv" => "Open TV",
			"opto" => "Opto",
			"optussports" => "Optus Sports",
			"orangetv" => "Orange TV",
			"Ottplay" => "Ottplay",
			"pbs" => "PBS",
			"pdc" => "PDCTV",
			"pantaya" => "Pantaya",
			"cbs" => "Paramount+",
			"peacocktv" => "Peacock TV",
			"philo" => "Philo",
			"playsuisse" => "Play Suisse",
			"plex" => "Plex TV",
			"plutotv" => "Pluto TV",
			"popcornflix" => "Popcornflix",
			"premiersports" => "Premier Sports",
			"prendetv" => "Prende TV",
			"iprima" => "Prima",
			"primasport" => "Prima Sport",
			"primevideo" => "Prime Video",
			"proximustv" => "Proximus TV",
			"puhutv" => "Puhu TV",
			"rmcsport" => "RMC Sport",
			"rsi" => "RSI",
			"rtbf" => "RTBF",
			"rteie" => "RTE Player",
			"rtlxl" => "RTL XL",
			"rtlplus" => "RTLPlus",
			"rtlplay" => "RTLplay",
			"rtp" => "RTP Play",
			"rtr" => "RTR",
			"rts" => "RTS",
			"rtsplaneta" => "RTS Planeta",
			"rtve" => "RTVE",
			"rtvs" => "RTVS",
			"ruv" => "RUV",
			"rai" => "Rai Play",
			"rakutentv" => "Rakuten TV",
			"redbox" => "Redbox",
			"retrocrushtv" => "RetroCrush TV",
			"rokuchannel" => "Roku Channel",
			"ssportplus" => "S Sport Plus",
			"sbs" => "SBS",
			"showtime" => "SHOWTIME",
			"shudder" => "SHUDDER",
			"sportdigital" => "SPORTDIGITAL",
			"srf" => "SRF",
			"starz" => "STARZ",
			"stirr" => "STIRR",
			"stv" => "STV Player",
			"svt" => "SVT",
			"salto" => "Salto",
			"serieapass" => "Serie A Pass",
			"servustv" => "ServusTV",
			"setantasports" => "Setanta Sports",
			"shahid" => "Shahid",
			"showmax" => "Showmax",
			"showtimeanytime" => "Showtime Anytime",
			"singtelcast" => "Singtel Cast",
			"sky" => "Sky",
			"skyshtime" => "Sky Showtime",
			"skysportsnow" => "Sky Sports Now",
			"slingtv" => "Sling TV",
			"smotreshka" => "Smotreshka",
			"sonyliv" => "SonyLIV",
			"sooka" => "Sooka",
			"sparksport" => "Spark Sport",
			"spectrum" => "Spectrum TV",
			"spoox" => "Spoox Skyperfect",
			"sport1" => "Sport1",
			"sportbox" => "Sportbox",
			"snnow" => "Sportsnet Now",
			"stan" => "Stan",
			"starplus" => "Star Plus",
			"starhubtvplus" => "StarHub TV",
			"sunnxt" => "Sun NXT",
			"sundancenow" => "Sundance Now",
			"tf1" => "TF1.fr",
			"tfc" => "TFC",
			"tg4" => "TG4",
			"timvision" => "TIMVISION",
			"tod" => "TOD TV",
			"toggo" => "TOGGO",
			"trtizle" => "TRT Izle",
			"tsn" => "TSN",
			"tv2" => "TV 2",
			"tvazteca" => "TV Azteca",
			"tvvlaanderen" => "TV Vlaanderen",
			"tvplustr" => "TV+ Turkey",
			"tv4play" => "TV4 Play",
			"tvnz" => "TVNZ",
			"tvo" => "TVO",
			"tvppl" => "TVP PL",
			"tvplayer" => "TVPlayer",
			"tver" => "TVer",
			"talktv" => "TalkTV",
			"tapgo" => "TapGo",
			"tataplay" => "Tata Play Binge",
			"tatasky" => "Tata Sky",
			"teleboy" => "Teleboy",
			"telenettv" => "Telenet TV",
			"telesport" => "Telesport",
			"telestat" => "Telestat",
			"telia" => "Telia",
			"threenow" => "ThreeNow",
			"tivify" => "Tivify",
			"trueid" => "TrueID",
			"tubitv" => "Tubi TV",
			"ufcarabia" => "UFC Arabia",
			"ufcfightpass" => "UFC Fight Pass",
			"uktv" => "UKTV",
			"usanetwork" => "USA Network",
			"vgtv" => "VGTV",
			"vh1" => "VH1",
			"viu" => "VIU",
			"viutv" => "VIU TV",
			"vrt" => "VRT NU",
			"vrv" => "VRV",
			"vtm" => "VTM GO",
			"vtvcab" => "VTV Cab",
			"vudu" => "VUDU",
			"viafree" => "Viafree",
			"viaplay" => "Viaplay",
			"vicetv" => "Vice TV",
			"videoland" => "Videoland",
			"vidio" => "Vidio",
			"viki" => "Viki",
			"vimeo" => "Vimeo",
			"virginmediatelevision" => "Virgin Media TV",
			"vivamax" => "VivaMax",
			"vivaro" => "Vivaro TV",
			"vix" => "Vix",
			"vootv" => "Voo TV+",
			"voot" => "Voot / Jio Cinema",
			"wppilot" => "WP Pilot",
			"wwe" => "WWE Network",
			"willowtv" => "Willow TV",
			"wink" => "Wink",
			"xfinity" => "Xfinity Stream",
			"xumo" => "Xumo",
			"yle" => "YLE",
			"wilmaa" => "Yallo TV",
			"yousee" => "YouSee",
			"youtube" => "Youtube",
			"zdf" => "ZDF",
			"zee5" => "ZEE5",
			"ziggo" => "ZIGGO GO",
			"zappn" => "Zappn TV",
			"zattoo" => "Zattoo"	
		),
	);

	public function __construct($debug=0) {
		
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$this->api_key = $settings->get_setting('controld_key');
		if(!$this->api_key){
			throw new SystemDisplayablePermanentError("Controld api keys are not present.");
			exit();			
		}
		/*
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			$this->api_key = $settings->get_setting('paypal_api_key_test');
			$this->api_secret_key = $settings->get_setting('paypal_api_secret_test');
			$this->endpoint = 'https://api-m.sandbox.paypal.com';
			$this->test_mode = true;
		}
		else{
			$this->api_key = $settings->get_setting('paypal_api_key');
			$this->api_secret_key = $settings->get_setting('paypal_api_secret');
			$this->endpoint = 'https://api-m.paypal.com';
			$this->test_mode = false;			
		}

		if(!$this->api_key || !$this->api_secret_key){
			throw new SystemDisplayablePermanentError("Paypal api keys are not present.");
			exit();			
		}
		
		$this->return_url = $settings->get_setting('webDir').'/cart_charge';
		$this->cancel_url = $settings->get_setting('webDir').'/cart'; 
		*/

	}


	//USERS
	public function listUsers(){
		return $this->getRequest('https://api.controld.com/users');
	}
	
	//PROFILES
	public function listProfiles(){
		return $this->getRequest('https://api.controld.com/profiles');
	}
	

	
	public function createProfile($name, $clone_profile_id=null){
		$data = array(
		'name' => $name,
		);
		
		if($clone_profile_id){
			$data['clone_profile_id'] = $clone_profile_id;
		}
		
		$endpoint = 'https://api.controld.com/profiles';

		if($debug){
			echo 'createProfile: '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->postRequest($endpoint, $data);
	}

	public function modifyProfile($profile_id, $name, $password, $data=array()){
		$data = array( 
			'status' => $status,
		);
		if($value){
			$data['value'] = $value;
		}
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/options'.$name;

		if($debug){
			echo 'createProfile:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->putRequest($endpoint, $data);
	}

	public function listProfileOptions(){
		return $this->getRequest('https://api.controld.com/profiles/options');
	}
	
	public function modifyProfileOptions($profile_id, $name, $status, $value=null){
		$data = array( 
			'status' => $status,  // 0 or 1
		);
		if($value){
			$data['value'] = $value;
		}
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/options'.$name;

		if($debug){
			echo 'modifyProfileOptions:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->putRequest($endpoint, $data);
	}
	
	public function deleteProfile($device_id){
		$endpoint = 'https://api.controld.com/profiles/'.$device_id;
		
		if($debug){
			echo 'deleteRuleFolder:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->deleteRequest($endpoint);
	}	
	
	//FILTERS
	public function listNativeFilters($profile_id){
		return $this->getRequest('https://api.controld.com/profiles/'.$profile_id.'/filters');
	}

	public function listExternalFilters($profile_id){
		return $this->getRequest('https://api.controld.com/profiles/'.$profile_id.'/filters/external');
	}		
	
	public function modifyProfileFilter($profile_id, $filter_key, $status){
		$data = array( 
			'status' => $status,
		);
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/filters/filter/'.$filter_key;

		if($debug){
			echo 'modifyProfileFilter:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->putRequest($endpoint, $data);
	}
	
	//RULE FOLDERS
	public function listRuleFolders($profile_id){
		return $this->getRequest('https://api.controld.com/profiles/'.$profile_id.'/groups');
	}	

	public function createRuleFolder($profile_id, $name, $status, $action='0'){
		$data = array( 
			'name' => $name,
			'status' => $status,
			'do' => $action, //Rule type. 0 = BLOCK. 1 = BYPASS, 2 = SPOOF, 3 = REDIRECT.
		);
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/groups';

		if($debug){
			echo 'createRuleFolder:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
			
		return $this->postRequest($endpoint, $data);
	}
	
	public function getRuleFolderIdFromName($profile_id, $name){
		$results = $this->listRuleFolders($profile_id);

		foreach($results['body']['groups'] as $result){
			if($result['group'] == $name){
				return $result['PK'];
			}
		}
	}
	
	public function deleteRuleFolder($profile_id, $name, $status){
		$folder_id = $this->getRuleFolderIdFromName($profile_id, $name);
		$data = array( 
			'status' => $status,
		);

		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/groups/'.$folder_id;

		if($debug){
			echo 'deleteRuleFolder:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->deleteRequest($endpoint, $data);
	}	
	
	//RULES
	public function listRules($profile_id, $rule_folder_id=0){
		if($rule_folder_id){
			$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/rules/'.$rule_folder_id;
		}
		else{
			$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/rules';
		}
		return $this->getRequest($endpoint);
	}	
	
	public function createRule($profile_id, $status, $hostnames, $rule_folder_id=null, $action='0'){
		$data = array( 
			'status' => $status,
			'do' => $action, //Rule type. 0 = BLOCK. 1 = BYPASS, 2 = SPOOF, 3 = REDIRECT.
			'hostnames' => $hostnames,
		);
		
		if($rule_folder_id){
			$data['group'] = $rule_folder_id;
		}
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/rules';
	
		if($debug){
			echo 'createRule:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}

	
		return $this->postRequest($endpoint, $data);
	}	
	
	public function modifyRule($profile_id, $status, $hostnames, $rule_folder_id=null, $action='0'){
		$data = array( 
			'status' => $status,
			'do' => $action, //Rule type. 0 = BLOCK. 1 = BYPASS, 2 = SPOOF, 3 = REDIRECT.
			'hostnames' => $hostnames,
		);
		
		if($rule_folder_id){
			$data['group'] = $rule_folder_id;
		}
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/rules';

		if($debug){
			echo 'modifyRule:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->putRequest($endpoint, $data);
	}	
	
	public function deleteRule($profile_id, $hostname){
		$data = array( 
		);

		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/rules/'.$hostname;

		if($debug){
			echo 'deleteRule:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->deleteRequest($endpoint, $data);
	}	
	
	//DEFAULT RULES 
	public function listDefaultRule($profile_id){
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/default';
		return $this->getRequest($endpoint);
	}	

	public function modifyDefaultRule($profile_id, $status, $action='0'){
		$data = array( 
			'status' => $status,
			'do' => $action, //Rule type. 0 = BLOCK. 1 = BYPASS, 2 = SPOOF, 3 = REDIRECT.
		);
		
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/default';
 
		if($debug){
			echo 'modifyDefaultRule:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}
		
		return $this->putRequest($endpoint, $data);
	}	
	
	
	//SERVICES 
	public function listServicesOnProfile($profile_id){
		return $this->getRequest('https://api.controld.com/profiles/'.$profile_id.'/services');
	}	
	
	
	public function modifyService($profile_id, $service_key, $status){
		$data = array( 
			'status' => $status,
			'do' => 0, //Rule type. 0 = BLOCK. 1 = BYPASS, 2 = SPOOF, 3 = REDIRECT.
		);
		$endpoint = 'https://api.controld.com/profiles/'.$profile_id.'/services/'.$service_key;
		
		if($debug){
			echo 'modifyService:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}		
		
		return $this->putRequest($endpoint, $data);
	}
	
	
	
	public function listAllServiceCategories(){
		return $this->getRequest('https://api.controld.com/services/categories');
	}	
	
	public function listAllServicesInCategory($category){
		return $this->getRequest('https://api.controld.com/services/categories/'.$category);
	}	
	
	//SCHEDULES
	public function listSchedules(){
		return $this->getRequest('https://api.controld.com/schedules');
	}
	
	
	//DOESN'T WORK?
	public function createSchedule($org_id, $device_id, $profile_id, $action, $enforcing, $time_start, $time_end, $time_zone, $weekdays){
		$data = array( 
			'org' => $org_id,
			'device' => $device_id,
			'enforcing' => $status,
			'name' => $action, 
			'time_start' => $hostnames,
			'time_end' => $hostnames,
			'time_zone' => $hostnames,
			'weekdays' => $weekdays,
			
		);
		
		if($rule_folder_id){
			$data['group'] = $rule_folder_id;
		}
		$endpoint = 'https://api.controld.com/schedules';
			
		return $this->postRequest($endpoint, $data);
	}	
	
	//DEVICES
	public function listDevices(){
		return $this->getRequest('https://api.controld.com/devices');
	}

	public function listDeviceTypes(){
		return $this->getRequest('https://api.controld.com/devices/types');
	}
	
	public function createDevice($data){
		
		$endpoint = 'https://api.controld.com/devices';
		
		if($debug){
			echo 'createDevice:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}			
		
		return $this->postRequest($endpoint, $data);
	}

	public function modifyDevice($device_id, $data){
		$endpoint = 'https://api.controld.com/devices/'.$device_id;
		
		if($debug){
			echo 'modifyDevice:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}			
		
		return $this->putRequest($endpoint, $data);
	}
	
	public function deleteDevice($device_id){
		
		$endpoint = 'https://api.controld.com/devices/'.$device_id;
		
		if($debug){
			echo 'deleteDevice:  '.$endpoint.' '.print_r($data).'<br>';
			return true;
		}			
		
		return $this->deleteRequest($endpoint);
	}
	
	
	
	
	private function postRequest($url, $data){
		$access_token=$this->api_key;
		$curl = curl_init();
		
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'POST',
		  CURLOPT_POSTFIELDS =>json_encode($data),
		  CURLOPT_HTTPHEADER => array(
			'accept: application/json',
			"Authorization: Bearer $access_token",
			'content-type: application/x-www-form-urlencoded',
		  ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		$result = json_decode($response,true);
		if ($result['success']){
			return $result;
		}
		else{
			echo 'Error: '. $result['error']['message'];
		}		
	}
	
	
	private function putRequest($url, $data){
		$access_token=$this->api_key;
		$curl = curl_init();
		
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'PUT',
		  CURLOPT_POSTFIELDS =>json_encode($data),
		  CURLOPT_HTTPHEADER => array(
			'accept: application/json',
			"Authorization: Bearer $access_token",
			'content-type: application/x-www-form-urlencoded',
		  ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		$result = json_decode($response,true);
		if ($result['success']){
			return $result;
		}
		else{
			echo 'Error: '. $result['error']['message'];
		}		
	}
	
	private function getRequest($url){
		$access_token = $this->api_key;	
		$curl=curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'GET',
		  CURLOPT_HTTPHEADER => array(
			"Authorization: Bearer $access_token"
		  ),
		));

		$response = curl_exec($curl);
		curl_close($curl);
		$result = json_decode($response,true);
		if ($result['success']){
			return $result;
		}
		else{
			echo 'Error: '. $result['error']['message'];
		}			
	}
	
	private function deleteRequest($url){
		$access_token = $this->api_key;	
		$curl=curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'DELETE',
		  CURLOPT_HTTPHEADER => array(
			"Authorization: Bearer $access_token"
		  ),
		));

		$response = curl_exec($curl);
		curl_close($curl);
		$result = json_decode($response,true);
		if ($result['success']){
			return $result;
		}
		else{
			echo 'Error: '. $result['error']['message'];
		}			
	}
	
	
}