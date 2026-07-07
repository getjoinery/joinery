package com.getjoinery.android

import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Environment
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.browser.customtabs.CustomTabsIntent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

/**
 * Webview state surfaced to the native chrome. The shell reads [pageTitle] and
 * [canGoBack] for the app bar; [WebScreen] writes all of it.
 */
class WebPageState {
    var isLoading by mutableStateOf(true)
    var failureMessage by mutableStateOf<String?>(null)
    var pageTitle by mutableStateOf("")
    var canGoBack by mutableStateOf(false)
}

/**
 * The authenticated webview — how every `{type: "web"}` navigation destination
 * renders (spec § Webview contract).
 *
 * - Same-origin only: off-site links (Custom Tabs) and non-web schemes (mailto,
 *   tel) leave the app; member-surface navigation stays in-webview.
 * - First use bridges the API session into an app-context web session; a
 *   logged-out redirect (`/login`) or an expired bridge token (410) silently
 *   re-bridges and retries once before surfacing anything. A dead API key makes
 *   the re-bridge mint 401, which signs the whole app out through the client
 *   handler.
 * - System back walks webview history first (this screen's [BackHandler]), then
 *   the shell's navigation. Pull-to-refresh, file uploads (SAF picker),
 *   downloads (DownloadManager), and loading/error/offline-retry states.
 *
 * The caller owns the app bar and reads the page title / back affordance from
 * [state].
 */
@Composable
fun WebScreen(
    target: String,
    client: ApiClient,
    web: WebSessionCoordinator,
    state: WebPageState,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val controller = remember(target) { WebController(context, target, client, web, state, scope) }

    val fileChooser = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        controller.onFileChooserResult(result.resultCode, result.data)
    }

    // System back walks webview history while there is any; when there is none,
    // this handler is disabled and the shell's navigation-back takes over.
    BackHandler(enabled = state.canGoBack) { controller.goBack() }

    Box(modifier.fillMaxSize()) {
        AndroidView(
            // The persistent marker that web content is hosted here — the
            // web_loading spinner below is transient, so tests key on this.
            modifier = Modifier.fillMaxSize().testTag("web_view"),
            factory = { ctx ->
                val swipe = SwipeRefreshLayout(ctx)
                val webView = WebView(ctx).apply {
                    layoutParams = ViewGroup.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT,
                        ViewGroup.LayoutParams.MATCH_PARENT,
                    )
                    settings.javaScriptEnabled = true
                    settings.domStorageEnabled = true
                    settings.useWideViewPort = true
                    settings.loadWithOverviewMode = true
                    settings.mediaPlaybackRequiresUserGesture = false
                    webViewClient = controller.webViewClient()
                    webChromeClient = controller.webChromeClient(fileChooser)
                    setDownloadListener(controller.downloadListener())
                }
                CookieManager.getInstance().apply {
                    setAcceptCookie(true)
                    setAcceptThirdPartyCookies(webView, true)
                }
                swipe.addView(webView)
                swipe.setOnRefreshListener { controller.refresh() }
                controller.attach(webView, swipe)
                controller.startInitialLoad()
                swipe
            },
        )

        if (state.isLoading) {
            CircularProgressIndicator(
                Modifier.align(Alignment.Center).testTag("web_loading")
            )
        }
        state.failureMessage?.let { message ->
            Column(
                Modifier.align(Alignment.Center).padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(
                    message,
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.testTag("web_error"),
                )
                Button(onClick = { controller.retry() }, modifier = Modifier.testTag("web_retry")) {
                    Text("Try Again")
                }
            }
        }
    }
}

/**
 * All webview policy lives here: bridging, logged-out detection, the link
 * policy, downloads, file uploads, and refresh. Not a composable — it holds the
 * live [WebView] and drives it imperatively from the delegate callbacks.
 */
private class WebController(
    private val context: Context,
    target: String,
    private val client: ApiClient,
    private val web: WebSessionCoordinator,
    private val state: WebPageState,
    private val scope: CoroutineScope,
) {
    private var webView: WebView? = null
    private var swipe: SwipeRefreshLayout? = null

    /** The path the user is meant to be on — re-bridges land back here. */
    private var intendedPath: String = target

    /** One silent re-bridge per load attempt; reset on success and refresh. */
    private var didRebridge = false

    /** Set while the current navigation is a bridge URL, so its completion marks
     *  the shared coordinator bridged. */
    private var bridging = false

    private var filePathCallback: ValueCallback<Array<Uri>>? = null

    fun attach(webView: WebView, swipe: SwipeRefreshLayout) {
        this.webView = webView
        this.swipe = swipe
    }

    // MARK: Loading

    fun startInitialLoad() {
        scope.launch { load(intendedPath) }
    }

    private suspend fun load(path: String) {
        val view = webView ?: return
        if (web.bridged) {
            view.loadUrl(web.pageUrl(path))
            return
        }
        bridge(path)
    }

    /** Mint a fresh single-use bridge token and drive the webview through it. A
     *  401 here means the API key is dead — the client handler signs the whole
     *  app out, so this screen only needs to stop quietly. */
    private suspend fun bridge(path: String) {
        val view = webView ?: return
        try {
            val bridgeUrl = web.bridgeUrl(path)
            bridging = true
            view.loadUrl(bridgeUrl)
        } catch (e: JoineryApiError) {
            if (e is JoineryApiError.Authentication) return
            fail(e.displayMessage)
        } catch (e: Exception) {
            fail("This page could not be loaded.")
        }
    }

    /** The logged-out signal: the site 302s member pages to `/login` when the
     *  bridged session is gone, and an expired bridge token renders 410. Silently
     *  re-bridge back to where the user was headed; only a second failure in the
     *  same attempt surfaces. */
    private fun handleLoggedOut() {
        if (didRebridge) {
            fail("Your session has expired. Pull down to refresh and try again.")
            return
        }
        didRebridge = true
        val path = intendedPath
        scope.launch { bridge(path) }
    }

    private fun fail(message: String) {
        state.isLoading = false
        state.failureMessage = message
        swipe?.isRefreshing = false
    }

    fun retry() {
        didRebridge = false
        state.failureMessage = null
        state.isLoading = true
        startInitialLoad()
    }

    fun refresh() {
        didRebridge = false
        if (web.bridged) webView?.reload() else startInitialLoad()
    }

    fun goBack() {
        webView?.goBack()
    }

    private fun isSameOrigin(uri: Uri): Boolean {
        val host = uri.host ?: return false
        val base = web.baseHost ?: return false
        return host == base
    }

    private fun openExternal(uri: Uri) {
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, uri)) }
    }

    private fun openExternalTab(uri: Uri) {
        runCatching { CustomTabsIntent.Builder().build().launchUrl(context, uri) }
            .onFailure { openExternal(uri) }
    }

    // MARK: Delegates

    fun webViewClient(): WebViewClient = object : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val url = request.url
            // Subframes (embedded payment fields, media) are the page's business.
            if (!request.isForMainFrame) return false

            val scheme = url.scheme?.lowercase()
            if (scheme != "http" && scheme != "https") {
                openExternal(url)
                return true
            }
            // Off-site: Custom Tabs (spec — same-origin only in the webview).
            if (!isSameOrigin(url)) {
                openExternalTab(url)
                return true
            }
            val path = url.path ?: "/"
            // Logged-out redirect → silent re-bridge.
            if (path == "/login" || path.startsWith("/login/")) {
                handleLoggedOut()
                return true
            }
            // Track where the user is headed so a mid-session re-bridge lands back
            // on the right page (bridge plumbing itself doesn't count).
            if (path != "/app_bridge") {
                val query = url.query
                intendedPath = if (query.isNullOrEmpty()) path else "$path?$query"
            }
            return false
        }

        override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
            state.isLoading = true
            state.failureMessage = null
        }

        override fun onPageFinished(view: WebView?, url: String?) {
            if (bridging) {
                bridging = false
                web.markBridged()
            }
            didRebridge = false
            state.isLoading = false
            state.canGoBack = view?.canGoBack() ?: false
            swipe?.isRefreshing = false
        }

        override fun doUpdateVisitedHistory(view: WebView?, url: String?, isReload: Boolean) {
            state.canGoBack = view?.canGoBack() ?: false
        }

        override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
            if (request?.isForMainFrame != true) return
            val offline = when (error?.errorCode) {
                WebViewClient.ERROR_HOST_LOOKUP,
                WebViewClient.ERROR_CONNECT,
                WebViewClient.ERROR_TIMEOUT -> true
                else -> false
            }
            fail(
                if (offline) "You appear to be offline. Pull down to refresh when you're back online."
                else "This page could not be loaded."
            )
        }

        override fun onReceivedHttpError(
            view: WebView?,
            request: WebResourceRequest?,
            errorResponse: WebResourceResponse?,
        ) {
            // An expired bridge token renders 410 — mint a fresh one.
            if (request?.isForMainFrame == true &&
                request.url.path == "/app_bridge" &&
                errorResponse?.statusCode == 410
            ) {
                handleLoggedOut()
            }
        }
    }

    fun webChromeClient(launcher: ActivityResultLauncher<Intent>): WebChromeClient =
        object : WebChromeClient() {
            override fun onReceivedTitle(view: WebView?, title: String?) {
                state.pageTitle = title ?: ""
            }

            override fun onShowFileChooser(
                webView: WebView?,
                callback: ValueCallback<Array<Uri>>?,
                params: FileChooserParams?,
            ): Boolean {
                filePathCallback?.onReceiveValue(null)
                filePathCallback = callback
                return try {
                    val intent = params?.createIntent()
                    if (intent != null) {
                        launcher.launch(intent)
                        true
                    } else {
                        filePathCallback = null
                        false
                    }
                } catch (e: Exception) {
                    filePathCallback = null
                    false
                }
            }
        }

    fun onFileChooserResult(resultCode: Int, data: Intent?) {
        val callback = filePathCallback ?: return
        filePathCallback = null
        callback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data))
    }

    /** Content the webview can't render (PDFs, attachments) hands off to
     *  DownloadManager, carrying the bridged session cookie so authenticated
     *  files download. Lands in the app's external Downloads dir (no runtime
     *  permission on any supported API) with a completion notification. */
    fun downloadListener() = android.webkit.DownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
        runCatching {
            val request = DownloadManager.Request(Uri.parse(url))
            CookieManager.getInstance().getCookie(url)?.let { request.addRequestHeader("Cookie", it) }
            request.addRequestHeader("User-Agent", userAgent)
            val name = URLUtil.guessFileName(url, contentDisposition, mimeType)
            request.setMimeType(mimeType)
            request.setTitle(name)
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            request.setDestinationInExternalFilesDir(context, Environment.DIRECTORY_DOWNLOADS, name)
            (context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
        }
    }
}
