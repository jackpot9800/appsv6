import { useEffect } from 'react';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useFrameworkReady } from '@/hooks/useFrameworkReady';
import { Platform } from 'react-native';
import { activateKeepAwake, deactivateKeepAwake } from 'expo-keep-awake';
import AsyncStorage from '@react-native-async-storage/async-storage';

export default function RootLayout() {
  useFrameworkReady();

  // Activer le mode anti-veille pour empêcher l'écran de s'éteindre
  useEffect(() => {
    if (Platform.OS !== 'web') {
      // Vérifier si le mode anti-veille est activé dans les paramètres
      const checkKeepAwakeSetting = async () => {
        try {
          const keepAwakeEnabled = await AsyncStorage.getItem('keep_awake_enabled');
          // Si le paramètre n'existe pas ou est activé, activer le mode anti-veille
          if (keepAwakeEnabled !== 'false') {
            console.log('Activating keep awake mode to prevent screen timeout');
            activateKeepAwake();
          }
        } catch (error) {
          console.error('Error checking keep awake setting:', error);
          // Par défaut, activer le mode anti-veille
          activateKeepAwake();
        }
      };
      
      checkKeepAwakeSetting();
      
      // Nettoyer lors du démontage du composant
      return () => {
        console.log('Deactivating keep awake mode');
        deactivateKeepAwake();
      };
    }
  }, []);

  return (
    <>
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="presentation/[id]" options={{ headerShown: false }} />
        <Stack.Screen name="+not-found" />
      </Stack>
      <StatusBar style="light" />
    </>
  );
}