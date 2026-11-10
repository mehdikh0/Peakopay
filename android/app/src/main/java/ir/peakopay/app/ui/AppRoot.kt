package ir.peakopay.app.ui

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.ui.cards.CardsScreen
import ir.peakopay.app.ui.diagnostics.DiagnosticsScreen
import ir.peakopay.app.ui.home.HomeScreen
import ir.peakopay.app.ui.pairing.PairingScreen
import ir.peakopay.app.ui.pending.PendingScreen
import ir.peakopay.app.ui.status.StatusScreen
import ir.peakopay.app.ui.theme.PeakopayTheme
import ir.peakopay.app.ui.wizard.SetupWizard

private enum class Screen {
    WIZARD, PAIRING, HOME,
    STATUS, CARDS, DIAGNOSTICS, PENDING, STORES, ACCOUNT, NOTIFICATIONS, ADD_STORE,
}

/**
 * ریشه اپ — تک‌اکتیویتی (دور ۲۴ — ناوبری مطابق طرح):
 *   نصب اول ← ویزارد ۱۰ گامی → (جفت‌شده) HomeScreen چهارتبی + منوی پایین
 *   ویزارد رد شده ← PairingScreen
 * تب «حساب» → صفحه‌های حساب/اعلان‌ها/پشتیبانی/فروشگاه‌ها/…
 */
@Composable
fun AppRoot() {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    var screen by remember {
        mutableStateOf(
            when {
                prefs.isPaired -> Screen.HOME
                !prefs.wizardDone -> Screen.WIZARD
                else -> Screen.PAIRING
            },
        )
    }
    // دور ۲۵ — افزودن فروشگاه: پس از موفقیت/انصراف به همان صفحه‌ای برگردیم که از آن آمدیم
    var addStoreOrigin by remember { mutableStateOf(Screen.HOME) }

    PeakopayTheme {
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            when (screen) {
                Screen.WIZARD -> SetupWizard(
                    onFinish = { screen = if (Prefs.get(context).isPaired) Screen.HOME else Screen.PAIRING },
                )
                Screen.PAIRING -> PairingScreen(
                    onPaired = { screen = Screen.HOME },
                )
                Screen.HOME -> HomeScreen(
                    onDisconnect = {
                        Prefs.get(context).clear()
                        screen = Screen.PAIRING
                    },
                    onOpenStatus = { screen = Screen.STATUS },
                    onOpenCards = { screen = Screen.CARDS },
                    onOpenDiagnostics = { screen = Screen.DIAGNOSTICS },
                    onOpenPending = { screen = Screen.PENDING },
                    onOpenStores = { screen = Screen.STORES },
                    onOpenAccount = { screen = Screen.ACCOUNT },
                    onOpenNotifications = { screen = Screen.NOTIFICATIONS },
                    onAddStore = {
                        addStoreOrigin = Screen.HOME
                        screen = Screen.ADD_STORE
                    },
                )
                Screen.STATUS -> StatusScreen(onBack = { screen = Screen.HOME })
                Screen.CARDS -> CardsScreen(onBack = { screen = Screen.HOME })
                Screen.DIAGNOSTICS -> DiagnosticsScreen(onBack = { screen = Screen.HOME })
                Screen.PENDING -> PendingScreen(onBack = { screen = Screen.HOME })
                Screen.STORES -> ir.peakopay.app.ui.stores.StoresScreen(
                    onBack = { screen = Screen.HOME },
                    onAddStore = {
                        addStoreOrigin = Screen.STORES
                        screen = Screen.ADD_STORE
                    },
                )
                Screen.ACCOUNT -> ir.peakopay.app.ui.account.AccountScreen(onBack = { screen = Screen.HOME })
                Screen.NOTIFICATIONS -> ir.peakopay.app.ui.account.NotificationsScreen(onBack = { screen = Screen.HOME })
                Screen.ADD_STORE -> ir.peakopay.app.ui.pairing.PairingScreen(
                    onPaired = { screen = Screen.HOME },
                    onCancel = { screen = addStoreOrigin },
                    addMode = true,
                )
            }
        }
    }
}
