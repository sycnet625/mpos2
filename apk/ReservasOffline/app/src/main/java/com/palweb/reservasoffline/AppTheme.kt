package com.palweb.reservasoffline

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

private val LightScheme = lightColorScheme(
    primary = Color(0xFF4F46E5),
    onPrimary = Color.White,
    secondary = Color(0xFF0EA5E9),
    onSecondary = Color.White,
    tertiary = Color(0xFFF59E0B),
    background = Color(0xFFF1F5F9),
    surface = Color.White,
    surfaceVariant = Color(0xFFE2E8F0),
    outline = Color(0xFFCBD5E1),
)

private val DarkScheme = darkColorScheme(
    primary = Color(0xFF818CF8),
    secondary = Color(0xFF38BDF8),
    tertiary = Color(0xFFFBBF24),
)

private val AppShapes = Shapes(
    extraSmall = androidx.compose.foundation.shape.RoundedCornerShape(8.dp),
    small = androidx.compose.foundation.shape.RoundedCornerShape(12.dp),
    medium = androidx.compose.foundation.shape.RoundedCornerShape(16.dp),
    large = androidx.compose.foundation.shape.RoundedCornerShape(22.dp),
    extraLarge = androidx.compose.foundation.shape.RoundedCornerShape(28.dp),
)

@Composable
fun ReservasOfflineTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (isSystemInDarkTheme()) DarkScheme else LightScheme,
        shapes = AppShapes,
        typography = Typography(),
        content = content,
    )
}
