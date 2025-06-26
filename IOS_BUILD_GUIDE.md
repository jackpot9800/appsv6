# 📱 Guide de création d'une version iOS - Presentation Kiosk

## 🎯 Prérequis pour iOS

### Matériel requis
- **Mac** (macOS 10.15+ recommandé)
- **iPhone/iPad** pour les tests (optionnel)
- **Apple TV** pour tester la version TV (optionnel)

### Comptes et licences
- **Compte développeur Apple** ($99/an)
- **Xcode** (gratuit sur Mac App Store)

## 🚀 Méthodes de build iOS

### Méthode 1: EAS Build (Recommandée - Cloud)

Cette méthode fonctionne même sans Mac !

```bash
# 1. Installer EAS CLI
npm install -g @expo/eas-cli

# 2. Se connecter à Expo
eas login

# 3. Configurer le projet pour iOS
eas build:configure

# 4. Lancer le build iOS
eas build --platform ios --profile production
```

**Avantages :**
- ✅ Fonctionne sans Mac
- ✅ Build dans le cloud
- ✅ Gestion automatique des certificats
- ✅ Compatible Apple TV

### Méthode 2: Build local (Nécessite un Mac)

```bash
# 1. Installer Xcode depuis l'App Store
# 2. Installer les outils de ligne de commande
xcode-select --install

# 3. Générer le projet iOS
npx expo run:ios

# 4. Ou pour Apple TV spécifiquement
npx expo run:ios --scheme YourApp-tvOS
```

## 📱 Configuration spécifique iOS

### Mise à jour d'app.json pour iOS

```json
{
  "expo": {
    "name": "Presentation Kiosk",
    "slug": "presentation-kiosk-ios",
    "version": "1.0.0",
    "orientation": "landscape",
    "platforms": ["ios", "android", "web"],
    "ios": {
      "bundleIdentifier": "com.yourcompany.presentationkiosk",
      "buildNumber": "1",
      "supportsTablet": true,
      "requireFullScreen": true,
      "userInterfaceStyle": "dark",
      "infoPlist": {
        "UIRequiredDeviceCapabilities": ["arm64"],
        "UIStatusBarHidden": true,
        "UIViewControllerBasedStatusBarAppearance": false,
        "NSAppTransportSecurity": {
          "NSAllowsArbitraryLoads": true
        }
      }
    },
    "tvos": {
      "bundleIdentifier": "com.yourcompany.presentationkiosk.tvos",
      "buildNumber": "1",
      "icon": "./assets/images/tv-icon.png"
    }
  }
}
```

### Configuration pour Apple TV

```json
{
  "expo": {
    "tvos": {
      "bundleIdentifier": "com.yourcompany.presentationkiosk.tvos",
      "buildNumber": "1",
      "icon": "./assets/images/tv-icon.png",
      "topShelfImage": "./assets/images/tv-top-shelf.png",
      "infoPlist": {
        "UIUserInterfaceIdiom": "tv",
        "UIRequiredDeviceCapabilities": ["arm64"]
      }
    }
  }
}
```

## 🔧 Adaptations code pour iOS

### Gestion de la télécommande Apple TV

```typescript
// hooks/useAppleTVRemote.ts
import { useEffect } from 'react';
import { Platform } from 'react-native';

export function useAppleTVRemote(onRemoteEvent: (event: any) => void) {
  useEffect(() => {
    if (Platform.OS === 'ios' && Platform.isTV) {
      // Gestion des événements télécommande Apple TV
      const TVEventHandler = require('react-native').TVEventHandler;
      const tvEventHandler = new TVEventHandler();
      
      tvEventHandler.enable(null, (cmp: any, evt: any) => {
        if (evt && evt.eventType) {
          onRemoteEvent(evt);
        }
      });

      return () => {
        tvEventHandler.disable();
      };
    }
  }, [onRemoteEvent]);
}
```

### Adaptation du service API pour iOS

```typescript
// services/ApiService.ts - Ajouts pour iOS
class ApiService {
  private async makeRequest<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    // Configuration spécifique iOS pour contourner ATS
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'User-Agent': 'PresentationKiosk/2.0 (iOS; AppleTV; Compatible)',
      ...options.headers,
    };

    // Pour iOS, ajouter des headers spécifiques
    if (Platform.OS === 'ios') {
      headers['X-Platform'] = 'ios';
      headers['X-Device-Type'] = Platform.isTV ? 'appletv' : 'ios';
    }

    const response = await fetch(url, {
      ...options,
      headers,
    });

    // Gestion spécifique des erreurs iOS
    if (!response.ok) {
      if (Platform.OS === 'ios' && response.status === 0) {
        throw new Error('Erreur de réseau iOS. Vérifiez les paramètres ATS dans Info.plist');
      }
    }

    return this.extractJsonFromResponse(await response.text());
  }
}
```

## 🎮 Navigation Apple TV

### Configuration des contrôles

```typescript
// components/AppleTVNavigation.tsx
import React, { useEffect, useState } from 'react';
import { View, StyleSheet, Platform } from 'react-native';

interface AppleTVNavigationProps {
  children: React.ReactNode;
  onMenuPress?: () => void;
  onPlayPause?: () => void;
}

export function AppleTVNavigation({ children, onMenuPress, onPlayPause }: AppleTVNavigationProps) {
  const [focusedElement, setFocusedElement] = useState(0);

  useEffect(() => {
    if (Platform.OS === 'ios' && Platform.isTV) {
      const TVEventHandler = require('react-native').TVEventHandler;
      const tvEventHandler = new TVEventHandler();
      
      tvEventHandler.enable(null, (cmp: any, evt: any) => {
        switch (evt.eventType) {
          case 'menu':
            onMenuPress?.();
            break;
          case 'playPause':
            onPlayPause?.();
            break;
          case 'select':
            // Gérer la sélection
            break;
          case 'up':
          case 'down':
          case 'left':
          case 'right':
            // Gérer la navigation directionnelle
            break;
        }
      });

      return () => tvEventHandler.disable();
    }
  }, [onMenuPress, onPlayPause]);

  return (
    <View style={styles.container}>
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
  },
});
```

## 📦 Build et distribution

### EAS Build pour iOS

```bash
# Build pour iPhone/iPad
eas build --platform ios --profile production

# Build pour Apple TV
eas build --platform ios --profile production --non-interactive
```

### Configuration EAS pour iOS

```json
{
  "build": {
    "production": {
      "ios": {
        "buildType": "archive",
        "enterpriseProvisioning": "universal"
      }
    },
    "appletv": {
      "ios": {
        "buildType": "archive",
        "scheme": "YourApp-tvOS"
      }
    }
  }
}
```

## 🍎 Spécificités Apple TV

### Icônes requises

Créez ces icônes pour Apple TV :
- **App Icon** : 1280x768px (PNG)
- **Top Shelf Image** : 1920x720px (PNG)
- **Launch Image** : 1920x1080px (PNG)

### Gestion du focus

```typescript
// components/FocusableButton.tsx
import React from 'react';
import { TouchableOpacity, Text, StyleSheet, Platform } from 'react-native';

interface FocusableButtonProps {
  title: string;
  onPress: () => void;
  focused?: boolean;
}

export function FocusableButton({ title, onPress, focused }: FocusableButtonProps) {
  return (
    <TouchableOpacity
      style={[
        styles.button,
        focused && styles.buttonFocused,
        Platform.isTV && styles.tvButton
      ]}
      onPress={onPress}
      hasTVPreferredFocus={focused}
    >
      <Text style={[styles.text, focused && styles.textFocused]}>
        {title}
      </Text>
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  button: {
    backgroundColor: '#007AFF',
    padding: 12,
    borderRadius: 8,
    margin: 4,
  },
  buttonFocused: {
    backgroundColor: '#0051D5',
    transform: [{ scale: 1.1 }],
  },
  tvButton: {
    padding: 16,
    borderRadius: 12,
  },
  text: {
    color: 'white',
    textAlign: 'center',
    fontSize: 16,
  },
  textFocused: {
    fontWeight: 'bold',
  },
});
```

## 📋 Checklist iOS

### Avant le build
- [ ] Compte développeur Apple configuré
- [ ] Bundle identifier unique défini
- [ ] Icônes iOS/tvOS créées
- [ ] Configuration ATS pour HTTP
- [ ] Tests sur simulateur iOS

### Pour Apple TV
- [ ] Navigation télécommande implémentée
- [ ] Focus management configuré
- [ ] Interface adaptée pour TV (grandes polices, espacement)
- [ ] Top Shelf image créée
- [ ] Tests sur simulateur Apple TV

### Distribution
- [ ] Certificats de distribution configurés
- [ ] Profil de provisioning créé
- [ ] App Store Connect configuré
- [ ] Métadonnées et captures d'écran préparées

## 🚀 Déploiement

### TestFlight (Beta)
```bash
# Build et upload automatique vers TestFlight
eas build --platform ios --profile production --auto-submit
```

### App Store
1. Build avec EAS ou Xcode
2. Upload vers App Store Connect
3. Configurer les métadonnées
4. Soumettre pour review

## 🔧 Dépannage iOS

### Erreurs courantes

**Erreur de certificat :**
```bash
# Nettoyer les certificats
eas credentials:configure --platform ios
```

**Erreur ATS (App Transport Security) :**
Ajoutez dans app.json :
```json
{
  "ios": {
    "infoPlist": {
      "NSAppTransportSecurity": {
        "NSAllowsArbitraryLoads": true
      }
    }
  }
}
```

**Problème de focus Apple TV :**
```typescript
// Forcer le focus sur un élément
<TouchableOpacity hasTVPreferredFocus={true}>
```

## 💡 Conseils

1. **Testez d'abord sur simulateur** avant de build pour appareil
2. **Utilisez EAS Build** pour éviter les problèmes de configuration
3. **Préparez les icônes** aux bonnes dimensions dès le début
4. **Testez la navigation télécommande** sur Apple TV
5. **Configurez ATS** pour permettre HTTP si nécessaire

## 🎉 Résultat final

Vous obtiendrez :
- **App iOS** compatible iPhone/iPad
- **App Apple TV** avec navigation télécommande
- **Distribution** via App Store ou TestFlight
- **Interface** adaptée aux spécificités iOS

L'application sera optimisée pour l'écosystème Apple tout en conservant les fonctionnalités de présentation ! 🍎📱