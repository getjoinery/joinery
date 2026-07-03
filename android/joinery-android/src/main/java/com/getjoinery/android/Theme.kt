package com.getjoinery.android

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** Minimal brand theme: the app's accent color drives the Material primary,
 *  everything else is Material 3 defaults. Themes are per-app via [JoineryConfig]. */
@Composable
fun JoineryTheme(accent: Color, content: @Composable () -> Unit) {
    val colorScheme = if (isSystemInDarkTheme()) {
        darkColorScheme(primary = accent)
    } else {
        lightColorScheme(primary = accent)
    }
    MaterialTheme(colorScheme = colorScheme, content = content)
}
