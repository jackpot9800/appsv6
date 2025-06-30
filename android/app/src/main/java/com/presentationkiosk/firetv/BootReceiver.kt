package com.presentationkiosk.firetv

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Récepteur de démarrage qui lance l'application automatiquement
 * lorsque l'appareil Fire TV démarre
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED ||
            intent.action == "android.intent.action.QUICKBOOT_POWERON") {
            
            Log.d("BootReceiver", "Boot completed, starting Presentation Kiosk")
            
            try {
                // Ajouter un délai pour s'assurer que le système est complètement démarré
                Thread.sleep(5000)
                
                // Créer l'intent pour démarrer l'application
                val launchIntent = Intent(context, MainActivity::class.java)
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                
                // Démarrer l'application
                context.startActivity(launchIntent)
                
                Log.d("BootReceiver", "Application started successfully")
            } catch (e: Exception) {
                Log.e("BootReceiver", "Error starting application: ${e.message}")
                e.printStackTrace()
            }
        }
    }
}