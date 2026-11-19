# Add project specific ProGuard rules here.
# You can control the set of applied configuration files using the
# proguardFiles setting in build.gradle.
#
# For more details, see
#   http://developer.android.com/guide/developing/tools/proguard.html

# If your project uses WebView with JS, uncomment the following
# and specify the fully qualified class name to the JavaScript interface
# class:
#-keepclassmembers class fqcn.of.javascript.interface.for.webview {
#   public *;
#}

# Uncomment this to preserve the line number information for
# debugging stack traces.
#-keepattributes SourceFile,LineNumberTable

# If you keep the line number information, uncomment this to
# hide the original source file name.
#-renamesourcefileattribute SourceFile
# --- HELP-ME : règles pour la version publiée (R8) ---
# Modèles lus/écrits par Firebase et Gson par réflexion
-keep class com.hightechniger.help_me.models.** { *; }
-keep class com.hightechniger.help_me.Question { *; }
-keep class com.hightechniger.help_me.Note { *; }
-keep class com.hightechniger.help_me.Item { *; }
-keep class com.hightechniger.help_me.DataItem { *; }
-keep class com.hightechniger.help_me.MessageModel { *; }
-keepattributes Signature, *Annotation*, InnerClasses, EnclosingMethod
# Images des leçons chargées par leur nom (getIdentifier)
-keep class **.R$drawable { *; }
# Lecteur PDF
-keep class com.shockwave.** { *; }
-dontwarn org.slf4j.**
