import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
    id("com.google.devtools.ksp")
}

android {
    namespace = "ir.peakopay.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "ir.peakopay.app"
        minSdk = 26          // Android 8.0+ — پوشش گسترده گوشی‌های فعال در ایران
        targetSdk = 35
        versionCode = 22
        versionName = "2.3.1"

        // اپ فارسی‌اول است؛ منابع فارسی پیش‌فرض‌اند
        resourceConfigurations += listOf("fa")
    }

    val keystoreProps = Properties().apply {
        val f = rootProject.file("keystore.properties")
        if (f.exists()) f.inputStream().use { load(it) }
    }
    signingConfigs {
        create("release") {
            if (keystoreProps.isNotEmpty()) {
                storeFile = rootProject.file(keystoreProps.getProperty("storeFile"))
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
        debug {
            applicationIdSuffix = ".debug"
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
    }
    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    // Compose
    val composeBom = platform("androidx.compose:compose-bom:2024.09.02")
    implementation(composeBom)
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("androidx.navigation:navigation-compose:2.8.1")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.6")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.6")
    debugImplementation("androidx.compose.ui:ui-tooling")

    // Coroutines
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")

    // JSON
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.2")

    // Networking
    implementation("com.squareup.okhttp3:okhttp:4.12.0")

    // Images (لوگوی سایت فروشگاه‌ها)
    implementation("io.coil-kt:coil-compose:2.7.0")

    // Room (صف ارسال تراکنش‌ها + تاریخچه)
    implementation("androidx.room:room-runtime:2.6.1")
    implementation("androidx.room:room-ktx:2.6.1")
    ksp("androidx.room:room-compiler:2.6.1")

    // WorkManager (ارسال با تأخیر/تلاش مجدد + heartbeat)
    implementation("androidx.work:work-runtime-ktx:2.9.1")

    // Core
    implementation("androidx.core:core-ktx:1.13.1")

    // JVM unit tests (موتور خالص پارسر/فرستنده — بدون نیاز به شبیه‌گر)
    testImplementation("junit:junit:4.13.2")
    implementation("androidx.core:core-splashscreen:1.0.1")
}
