package ir.peakopay.app

import android.graphics.Color
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import ir.peakopay.app.ui.AppRoot
import ir.peakopay.app.ui.theme.PeakopayTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        // دور ۲۵ — طرح مرجع پس‌زمینهٔ ثابت دارد:
        //   نوار وضعیت همیشه روی هدر بنفش عمیق است → آیکون‌های روشن (سفید) همیشه.
        //   نوار پایین همیشه سفید/لاوندر است → آیکون‌های تیره همیشه.
        // (قبلاً auto بود: در لایت‌مودِ سیستم آیکون تیره روی هدر بنفش می‌افتاد و دیده نمی‌شد.)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.light(Color.TRANSPARENT, Color.TRANSPARENT),
        )
        setContent {
            PeakopayTheme {
                AppRoot()
            }
        }
    }
}
