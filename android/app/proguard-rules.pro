# Peakopay — ProGuard/R8

# kotlinx-serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class kotlinx.serialization.json.** { *** Companion; }
-keepclasseswithmembers class kotlinx.serialization.json.** { kotlinx.serialization.KSerializer serializer(...); }
-keep,includedescriptorclasses class ir.peakopay.app.**$$serializer { *; }
-keepclassmembers class ir.peakopay.app.** { *** Companion; }
-keepclasseswithmembers class ir.peakopay.app.** { kotlinx.serialization.KSerializer serializer(...); }

# OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**

# WorkManager instantiates workers reflectively
-keep class * extends androidx.work.ListenableWorker { <init>(...); }
# Manifest-registered receivers must keep their no-arg constructor
-keep class * extends android.content.BroadcastReceiver { <init>(); }
