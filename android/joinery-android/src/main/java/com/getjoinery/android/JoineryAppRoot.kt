package com.getjoinery.android

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag

/**
 * The app shell a brand module mounts as its root composable. Owns the
 * [SessionController] and switches between launch, login, upgrade, and the
 * signed-in surface.
 *
 * The signed-in surface is the server-driven navigation shell (bottom bar +
 * More + the authenticated webview), fed by `GET /api/v1/app/navigation`.
 */
@Composable
fun JoineryAppRoot(config: JoineryConfig, storeFileName: String) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val session = remember { SessionController.create(context, config, storeFileName, scope) }

    JoineryTheme(config.accentColor) {
        Box(Modifier.fillMaxSize()) {
            when (val state = session.state) {
                is SessionController.State.Launching ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.testTag("root_launching"))
                    }
                is SessionController.State.LoggedOut ->
                    LoginScreen(session)
                is SessionController.State.UpgradeRequired ->
                    UpgradeRequiredScreen(config, state.message)
                is SessionController.State.LoggedIn ->
                    NavigationShell(session, state.user)
            }
        }
    }

    LaunchedEffect(Unit) { session.bootstrap() }
}
