plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.kapt")
}

import java.io.File
import java.util.Properties

val keystorePropsFile = rootProject.file("keystore.properties")
val keystoreProps = Properties().apply {
    if (keystorePropsFile.exists()) {
        keystorePropsFile.inputStream().use { load(it) }
    }
}

val versionPropsFile = rootProject.file("version.properties")
val versionProps = Properties().apply {
    if (versionPropsFile.exists()) {
        versionPropsFile.inputStream().use { load(it) }
    } else {
        setProperty("VERSION_CODE", "1")
        setProperty("VERSION_NAME", "1.0.0")
    }
}

fun bumpVersionName(current: String): String {
    val parts = current.split(".")
    return if (parts.size == 3) {
        val major = parts[0].toIntOrNull() ?: 1
        val minor = parts[1].toIntOrNull() ?: 0
        val patch = (parts[2].toIntOrNull() ?: 0) + 1
        "$major.$minor.$patch"
    } else "1.0.1"
}

val currentVersionCode = (versionProps.getProperty("VERSION_CODE") ?: "1").toIntOrNull() ?: 1
val currentVersionName = versionProps.getProperty("VERSION_NAME") ?: "1.0.0"
val isReleaseBuild = gradle.startParameter.taskNames.any { it.contains("release", ignoreCase = true) }
val targetVersionCode = if (isReleaseBuild) currentVersionCode + 1 else currentVersionCode
val targetVersionName = if (isReleaseBuild) bumpVersionName(currentVersionName) else currentVersionName

if (isReleaseBuild) {
    versionProps.setProperty("VERSION_CODE", targetVersionCode.toString())
    versionProps.setProperty("VERSION_NAME", targetVersionName)
    versionPropsFile.outputStream().use { versionProps.store(it, "Auto-incremented on release build") }
}

android {
    namespace = "com.palweb.reservasoffline"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.palweb.reservasoffline"
        minSdk = 26
        targetSdk = 34
        versionCode = targetVersionCode
        versionName = targetVersionName

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "DEFAULT_API_PATH", "\"/api/reservas_offline.php\"")
        buildConfigField("String", "DEFAULT_OTA_JSON_PATH", "\"/api/reservas_offline_ota.php\"")
    }

    signingConfigs {
        create("release") {
            storeFile = file(keystoreProps.getProperty("storeFile", "release-key.jks"))
            storePassword = keystoreProps.getProperty("storePassword", "")
            keyAlias = keystoreProps.getProperty("keyAlias", "")
            keyPassword = keystoreProps.getProperty("keyPassword", "")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            if (keystorePropsFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
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
    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.6")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.6")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.6")

    implementation(platform("androidx.compose:compose-bom:2024.09.00"))
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")

    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")

    implementation("androidx.room:room-runtime:2.6.1")
    implementation("androidx.room:room-ktx:2.6.1")
    kapt("androidx.room:room-compiler:2.6.1")

    implementation("androidx.work:work-runtime-ktx:2.9.1")

    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
    androidTestImplementation(platform("androidx.compose:compose-bom:2024.09.00"))
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    testImplementation("junit:junit:4.13.2")
}

afterEvaluate {
    tasks.matching { it.name == "assembleRelease" }.configureEach {
        doLast {
            val releaseApk = layout.buildDirectory.file("outputs/apk/release/app-release.apk").get().asFile
            val otaApk = File(rootProject.projectDir.parentFile, "reservas.apk")
            if (releaseApk.exists()) {
                releaseApk.copyTo(otaApk, overwrite = true)
            }
        }
    }
}
