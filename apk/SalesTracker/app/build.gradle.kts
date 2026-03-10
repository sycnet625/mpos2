plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

import java.util.Properties

val versionPropsFile = rootProject.file("version.properties")
val versionProps = Properties().apply {
    if (versionPropsFile.exists()) {
        versionPropsFile.inputStream().use { load(it) }
    } else {
        setProperty("VERSION_CODE", "110")
        setProperty("VERSION_NAME", "1.10")
    }
}

fun bumpVersionName(current: String): String {
    val m = Regex("""^(\d+)\.(\d+)$""").matchEntire(current)
    if (m != null) {
        val major = m.groupValues[1].toInt()
        val minor = m.groupValues[2].toInt() + 1
        return "$major.$minor"
    }
    return "1.11"
}

val currentVersionCode = (versionProps.getProperty("VERSION_CODE") ?: "110").toIntOrNull() ?: 110
val currentVersionName = versionProps.getProperty("VERSION_NAME") ?: "1.10"
val nextVersionCode = currentVersionCode + 1
val nextVersionName = bumpVersionName(currentVersionName)

versionProps.setProperty("VERSION_CODE", nextVersionCode.toString())
versionProps.setProperty("VERSION_NAME", nextVersionName)
versionPropsFile.outputStream().use { versionProps.store(it, "Auto-incremented on configuration") }
println("Using versionCode=$nextVersionCode versionName=$nextVersionName")

android {
    namespace = "com.example.salestracker"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.example.salestracker"
        minSdk = 26
        targetSdk = 34
        versionCode = nextVersionCode
        versionName = nextVersionName

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    composeOptions {
        kotlinCompilerExtensionVersion = "1.5.14"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.activity:activity-ktx:1.9.2")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation(platform("androidx.compose:compose-bom:2024.09.00"))
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.6")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
