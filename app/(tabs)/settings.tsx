import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  TextInput,
  TouchableOpacity,
  Switch,
  ScrollView,
  Alert,
  ActivityIndicator,
  Platform,
} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { apiService } from '@/services/ApiService';
import { statusService } from '@/services/StatusService';
import { getWebSocketService, initWebSocketService } from '@/services/WebSocketService';
import { Monitor, Server, Radio, Wifi, RefreshCw, Info, Check, X, Power } from 'lucide-react-native';

export default function SettingsScreen() {
  const [serverUrl, setServerUrl] = useState('');
  const [deviceName, setDeviceName] = useState('');
  const [deviceId, setDeviceId] = useState('');
  const [isRegistered, setIsRegistered] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [testResult, setTestResult] = useState<'success' | 'error' | null>(null);
  const [testMessage, setTestMessage] = useState('');
  const [debugInfo, setDebugInfo] = useState<any>(null);
  const [showDebugInfo, setShowDebugInfo] = useState(false);
  const [keepAwakeEnabled, setKeepAwakeEnabled] = useState(true);
  const [webSocketEnabled, setWebSocketEnabled] = useState(true);
  const [webSocketUrl, setWebSocketUrl] = useState('');
  const [webSocketConnected, setWebSocketConnected] = useState(false);
  const [autoStartEnabled, setAutoStartEnabled] = useState(true);

  useEffect(() => {
    loadSettings();
  }, []);

  const loadSettings = async () => {
    try {
      // Charger l'URL du serveur
      const savedUrl = await AsyncStorage.getItem('server_url');
      if (savedUrl) {
        setServerUrl(savedUrl);
      }

      // Charger le nom de l'appareil
      const savedName = await AsyncStorage.getItem('device_name');
      if (savedName) {
        setDeviceName(savedName);
      } else {
        const generatedName = `Fire TV ${Math.floor(Math.random() * 1000)}`;
        setDeviceName(generatedName);
        await AsyncStorage.setItem('device_name', generatedName);
      }

      // Charger l'ID de l'appareil
      const savedId = await AsyncStorage.getItem('device_id');
      if (savedId) {
        setDeviceId(savedId);
      }

      // Charger le statut d'enregistrement
      const savedRegistration = await AsyncStorage.getItem('device_registered');
      setIsRegistered(savedRegistration === 'true');

      // Charger le paramètre de mode anti-veille
      const keepAwake = await AsyncStorage.getItem('keep_awake_enabled');
      setKeepAwakeEnabled(keepAwake !== 'false'); // Par défaut activé

      // Charger le paramètre WebSocket
      const wsEnabled = await AsyncStorage.getItem('websocket_enabled');
      setWebSocketEnabled(wsEnabled !== 'false'); // Par défaut activé

      // Charger l'URL du WebSocket
      const wsUrl = await AsyncStorage.getItem('websocket_url');
      if (wsUrl) {
        setWebSocketUrl(wsUrl);
      } else {
        // Générer une URL WebSocket par défaut basée sur l'URL du serveur
        if (savedUrl) {
          const defaultWsUrl = savedUrl.replace(/^http/, 'ws').replace(/\/index\.php$/, '/websocket');
          setWebSocketUrl(defaultWsUrl);
        }
      }

      // Charger le paramètre de démarrage automatique
      const autoStart = await AsyncStorage.getItem('auto_start_enabled');
      setAutoStartEnabled(autoStart !== 'false'); // Par défaut activé

      // Vérifier si le WebSocket est connecté
      const wsService = getWebSocketService();
      setWebSocketConnected(wsService?.isConnectedToServer() || false);

      // Charger les informations de debug
      const debugInfo = await apiService.getDebugInfo();
      setDebugInfo(debugInfo);
    } catch (error) {
      console.error('Error loading settings:', error);
      Alert.alert('Erreur', 'Impossible de charger les paramètres');
    }
  };

  const saveServerUrl = async () => {
    if (!serverUrl) {
      Alert.alert('Erreur', 'Veuillez entrer une URL de serveur');
      return;
    }

    setIsLoading(true);
    setTestResult(null);
    setTestMessage('');

    try {
      const success = await apiService.setServerUrl(serverUrl);
      
      if (success) {
        await AsyncStorage.setItem('server_url', serverUrl);
        setTestResult('success');
        setTestMessage('Connexion au serveur réussie');
        
        // Mettre à jour les informations de debug
        const debugInfo = await apiService.getDebugInfo();
        setDebugInfo(debugInfo);
        setIsRegistered(apiService.isDeviceRegistered());

        // Générer une URL WebSocket par défaut basée sur l'URL du serveur
        const defaultWsUrl = serverUrl.replace(/^http/, 'ws').replace(/\/index\.php$/, '/websocket');
        setWebSocketUrl(defaultWsUrl);
        await AsyncStorage.setItem('websocket_url', defaultWsUrl);
      } else {
        setTestResult('error');
        setTestMessage('Impossible de se connecter au serveur');
      }
    } catch (error) {
      console.error('Error saving server URL:', error);
      setTestResult('error');
      setTestMessage(error instanceof Error ? error.message : 'Erreur inconnue');
    } finally {
      setIsLoading(false);
    }
  };

  const saveDeviceName = async () => {
    if (!deviceName) {
      Alert.alert('Erreur', 'Veuillez entrer un nom d\'appareil');
      return;
    }

    try {
      await AsyncStorage.setItem('device_name', deviceName);
      await statusService.setDeviceName(deviceName);
      Alert.alert('Succès', 'Nom d\'appareil enregistré');
    } catch (error) {
      console.error('Error saving device name:', error);
      Alert.alert('Erreur', 'Impossible d\'enregistrer le nom d\'appareil');
    }
  };

  const resetDevice = async () => {
    Alert.alert(
      'Réinitialiser l\'appareil',
      'Êtes-vous sûr de vouloir réinitialiser cet appareil ? Toutes les données seront effacées.',
      [
        { text: 'Annuler', style: 'cancel' },
        { 
          text: 'Réinitialiser', 
          style: 'destructive',
          onPress: async () => {
            try {
              await apiService.resetDevice();
              await AsyncStorage.removeItem('device_registered');
              await AsyncStorage.removeItem('enrollment_token');
              await AsyncStorage.removeItem('assigned_presentation');
              await AsyncStorage.removeItem('default_presentation');
              
              setIsRegistered(false);
              setTestResult(null);
              setTestMessage('');
              
              Alert.alert('Succès', 'Appareil réinitialisé avec succès');
            } catch (error) {
              console.error('Error resetting device:', error);
              Alert.alert('Erreur', 'Impossible de réinitialiser l\'appareil');
            }
          }
        }
      ]
    );
  };

  const toggleKeepAwake = async (value: boolean) => {
    setKeepAwakeEnabled(value);
    await AsyncStorage.setItem('keep_awake_enabled', value.toString());
  };

  const toggleWebSocket = async (value: boolean) => {
    setWebSocketEnabled(value);
    await AsyncStorage.setItem('websocket_enabled', value.toString());
    
    if (value) {
      // Tenter de se connecter au WebSocket
      try {
        await initWebSocketService();
        setWebSocketConnected(true);
        Alert.alert('Succès', 'Connexion WebSocket établie');
      } catch (error) {
        console.error('Error connecting to WebSocket:', error);
        setWebSocketConnected(false);
        Alert.alert('Erreur', 'Impossible de se connecter au WebSocket');
      }
    } else {
      // Déconnecter le WebSocket
      const wsService = getWebSocketService();
      if (wsService) {
        wsService.disconnect();
        setWebSocketConnected(false);
      }
    }
  };

  const toggleAutoStart = async (value: boolean) => {
    setAutoStartEnabled(value);
    await AsyncStorage.setItem('auto_start_enabled', value.toString());
    
    if (Platform.OS === 'android') {
      if (value) {
        Alert.alert(
          'Démarrage automatique activé',
          'L\'application se lancera automatiquement au démarrage de l\'appareil Fire TV.'
        );
      } else {
        Alert.alert(
          'Démarrage automatique désactivé',
          'L\'application ne se lancera plus automatiquement au démarrage.'
        );
      }
    } else {
      Alert.alert(
        'Information',
        'Le démarrage automatique n\'est disponible que sur les appareils Fire TV/Android.'
      );
    }
  };

  const saveWebSocketUrl = async () => {
    if (!webSocketUrl) {
      Alert.alert('Erreur', 'Veuillez entrer une URL de WebSocket');
      return;
    }

    try {
      await AsyncStorage.setItem('websocket_url', webSocketUrl);
      
      // Tenter de se connecter au WebSocket
      if (webSocketEnabled) {
        try {
          await initWebSocketService();
          setWebSocketConnected(true);
          Alert.alert('Succès', 'Connexion WebSocket établie');
        } catch (error) {
          console.error('Error connecting to WebSocket:', error);
          setWebSocketConnected(false);
          Alert.alert('Erreur', 'Impossible de se connecter au WebSocket');
        }
      }
    } catch (error) {
      console.error('Error saving WebSocket URL:', error);
      Alert.alert('Erreur', 'Impossible d\'enregistrer l\'URL du WebSocket');
    }
  };

  const testWebSocketConnection = async () => {
    if (!webSocketUrl) {
      Alert.alert('Erreur', 'Veuillez entrer une URL de WebSocket');
      return;
    }

    try {
      setIsLoading(true);
      
      // Sauvegarder l'URL d'abord
      await AsyncStorage.setItem('websocket_url', webSocketUrl);
      
      // Tenter de se connecter au WebSocket
      await initWebSocketService();
      setWebSocketConnected(true);
      Alert.alert('Succès', 'Connexion WebSocket établie');
    } catch (error) {
      console.error('Error testing WebSocket connection:', error);
      setWebSocketConnected(false);
      Alert.alert('Erreur', 'Impossible de se connecter au WebSocket');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <ScrollView style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.title}>Paramètres</Text>
        <Text style={styles.subtitle}>Configuration de l'application</Text>
      </View>

      {/* Configuration du serveur */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <Server size={20} color="#3b82f6" />
          <Text style={styles.sectionTitle}>Configuration du serveur</Text>
        </View>
        
        <View style={styles.inputContainer}>
          <Text style={styles.label}>URL du serveur</Text>
          <TextInput
            style={styles.input}
            value={serverUrl}
            onChangeText={setServerUrl}
            placeholder="http://exemple.com/api/index.php"
            placeholderTextColor="#9ca3af"
            autoCapitalize="none"
            autoCorrect={false}
          />
        </View>
        
        <TouchableOpacity 
          style={[styles.button, isLoading && styles.buttonDisabled]}
          onPress={saveServerUrl}
          disabled={isLoading}
        >
          {isLoading ? (
            <ActivityIndicator size="small" color="#ffffff" />
          ) : (
            <Text style={styles.buttonText}>Tester et enregistrer</Text>
          )}
        </TouchableOpacity>
        
        {testResult && (
          <View style={[
            styles.resultContainer, 
            testResult === 'success' ? styles.successContainer : styles.errorContainer
          ]}>
            {testResult === 'success' ? (
              <Check size={16} color="#10b981" />
            ) : (
              <X size={16} color="#ef4444" />
            )}
            <Text style={[
              styles.resultText,
              testResult === 'success' ? styles.successText : styles.errorText
            ]}>
              {testMessage}
            </Text>
          </View>
        )}
      </View>

      {/* Configuration WebSocket */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <Radio size={20} color="#8b5cf6" />
          <Text style={styles.sectionTitle}>Configuration WebSocket</Text>
        </View>
        
        <View style={styles.switchContainer}>
          <Text style={styles.switchLabel}>Activer WebSocket</Text>
          <Switch
            value={webSocketEnabled}
            onValueChange={toggleWebSocket}
            trackColor={{ false: '#d1d5db', true: '#93c5fd' }}
            thumbColor={webSocketEnabled ? '#3b82f6' : '#f4f4f5'}
          />
        </View>
        
        {webSocketEnabled && (
          <>
            <View style={styles.inputContainer}>
              <Text style={styles.label}>URL du serveur WebSocket</Text>
              <TextInput
                style={styles.input}
                value={webSocketUrl}
                onChangeText={setWebSocketUrl}
                placeholder="ws://exemple.com:8080"
                placeholderTextColor="#9ca3af"
                autoCapitalize="none"
                autoCorrect={false}
              />
            </View>
            
            <TouchableOpacity 
              style={[styles.button, isLoading && styles.buttonDisabled]}
              onPress={testWebSocketConnection}
              disabled={isLoading}
            >
              {isLoading ? (
                <ActivityIndicator size="small" color="#ffffff" />
              ) : (
                <Text style={styles.buttonText}>Tester la connexion WebSocket</Text>
              )}
            </TouchableOpacity>
            
            <View style={styles.statusContainer}>
              <Text style={styles.statusLabel}>Statut WebSocket:</Text>
              <View style={styles.statusIndicatorContainer}>
                <View style={[
                  styles.statusDot,
                  webSocketConnected ? styles.statusDotConnected : styles.statusDotDisconnected
                ]} />
                <Text style={styles.statusText}>
                  {webSocketConnected ? 'Connecté' : 'Déconnecté'}
                </Text>
              </View>
            </View>
          </>
        )}
      </View>

      {/* Configuration de l'appareil */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <Monitor size={20} color="#10b981" />
          <Text style={styles.sectionTitle}>Configuration de l'appareil</Text>
        </View>
        
        <View style={styles.inputContainer}>
          <Text style={styles.label}>Nom de l'appareil</Text>
          <TextInput
            style={styles.input}
            value={deviceName}
            onChangeText={setDeviceName}
            placeholder="Fire TV Salon"
            placeholderTextColor="#9ca3af"
          />
        </View>
        
        <TouchableOpacity 
          style={styles.button}
          onPress={saveDeviceName}
        >
          <Text style={styles.buttonText}>Enregistrer le nom</Text>
        </TouchableOpacity>
        
        <View style={styles.infoContainer}>
          <Text style={styles.infoLabel}>ID de l'appareil:</Text>
          <Text style={styles.infoValue}>{deviceId}</Text>
        </View>
        
        <View style={styles.infoContainer}>
          <Text style={styles.infoLabel}>Statut d'enregistrement:</Text>
          <View style={styles.statusIndicatorContainer}>
            <View style={[
              styles.statusDot,
              isRegistered ? styles.statusDotConnected : styles.statusDotDisconnected
            ]} />
            <Text style={styles.statusText}>
              {isRegistered ? 'Enregistré' : 'Non enregistré'}
            </Text>
          </View>
        </View>
        
        <View style={styles.switchContainer}>
          <Text style={styles.switchLabel}>Mode anti-veille</Text>
          <Switch
            value={keepAwakeEnabled}
            onValueChange={toggleKeepAwake}
            trackColor={{ false: '#d1d5db', true: '#86efac' }}
            thumbColor={keepAwakeEnabled ? '#10b981' : '#f4f4f5'}
          />
        </View>

        {Platform.OS === 'android' && (
          <View style={styles.switchContainer}>
            <Text style={styles.switchLabel}>Démarrage automatique</Text>
            <Switch
              value={autoStartEnabled}
              onValueChange={toggleAutoStart}
              trackColor={{ false: '#d1d5db', true: '#93c5fd' }}
              thumbColor={autoStartEnabled ? '#3b82f6' : '#f4f4f5'}
            />
          </View>
        )}
      </View>

      {/* Actions avancées */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <RefreshCw size={20} color="#f59e0b" />
          <Text style={styles.sectionTitle}>Actions avancées</Text>
        </View>
        
        <TouchableOpacity 
          style={styles.dangerButton}
          onPress={resetDevice}
        >
          <Text style={styles.buttonText}>Réinitialiser l'appareil</Text>
        </TouchableOpacity>
        
        <TouchableOpacity 
          style={styles.infoButton}
          onPress={() => setShowDebugInfo(!showDebugInfo)}
        >
          <Text style={styles.buttonText}>
            {showDebugInfo ? 'Masquer les infos de debug' : 'Afficher les infos de debug'}
          </Text>
        </TouchableOpacity>
        
        {showDebugInfo && debugInfo && (
          <View style={styles.debugContainer}>
            <Text style={styles.debugTitle}>Informations de debug</Text>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>URL du serveur:</Text>
              <Text style={styles.debugValue}>{debugInfo.serverUrl || 'Non configurée'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>ID de l'appareil:</Text>
              <Text style={styles.debugValue}>{debugInfo.deviceId || 'Non généré'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Nom de l'appareil:</Text>
              <Text style={styles.debugValue}>{debugInfo.deviceName || 'Non configuré'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Enregistré:</Text>
              <Text style={styles.debugValue}>{debugInfo.isRegistered ? 'Oui' : 'Non'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Token:</Text>
              <Text style={styles.debugValue}>{debugInfo.hasToken ? 'Présent' : 'Absent'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Type d'API:</Text>
              <Text style={styles.debugValue}>{debugInfo.apiType || 'Non détecté'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>IP locale:</Text>
              <Text style={styles.debugValue}>{debugInfo.localIpAddress || 'Inconnue'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>IP externe:</Text>
              <Text style={styles.debugValue}>{debugInfo.externalIpAddress || 'Inconnue'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>WebSocket:</Text>
              <Text style={styles.debugValue}>
                {webSocketEnabled ? (webSocketConnected ? 'Connecté' : 'Déconnecté') : 'Désactivé'}
              </Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>URL WebSocket:</Text>
              <Text style={styles.debugValue}>{webSocketUrl || 'Non configurée'}</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Version:</Text>
              <Text style={styles.debugValue}>2.0.0</Text>
            </View>
            
            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Plateforme:</Text>
              <Text style={styles.debugValue}>{Platform.OS} {Platform.Version}</Text>
            </View>

            <View style={styles.debugItem}>
              <Text style={styles.debugLabel}>Démarrage auto:</Text>
              <Text style={styles.debugValue}>{autoStartEnabled ? 'Activé' : 'Désactivé'}</Text>
            </View>
          </View>
        )}
      </View>

      {/* Informations */}
      <View style={styles.section}>
        <View style={styles.sectionHeader}>
          <Info size={20} color="#6b7280" />
          <Text style={styles.sectionTitle}>Informations</Text>
        </View>
        
        <View style={styles.infoContainer}>
          <Text style={styles.infoLabel}>Version de l'application:</Text>
          <Text style={styles.infoValue}>2.0.0</Text>
        </View>
        
        <View style={styles.infoContainer}>
          <Text style={styles.infoLabel}>Plateforme:</Text>
          <Text style={styles.infoValue}>{Platform.OS}</Text>
        </View>
        
        <View style={styles.infoContainer}>
          <Text style={styles.infoLabel}>Version de la plateforme:</Text>
          <Text style={styles.infoValue}>{Platform.Version}</Text>
        </View>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0a0a0a',
  },
  header: {
    padding: 20,
    paddingBottom: 10,
  },
  title: {
    fontSize: 28,
    fontWeight: 'bold',
    color: '#ffffff',
    marginBottom: 4,
  },
  subtitle: {
    fontSize: 16,
    color: '#9ca3af',
  },
  section: {
    backgroundColor: '#1a1a1a',
    borderRadius: 12,
    padding: 16,
    marginHorizontal: 20,
    marginBottom: 20,
  },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 16,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#ffffff',
    marginLeft: 8,
  },
  inputContainer: {
    marginBottom: 16,
  },
  label: {
    fontSize: 14,
    fontWeight: '600',
    color: '#d1d5db',
    marginBottom: 8,
  },
  input: {
    backgroundColor: '#2a2a2a',
    borderRadius: 8,
    padding: 12,
    color: '#ffffff',
    fontSize: 16,
  },
  button: {
    backgroundColor: '#3b82f6',
    borderRadius: 8,
    padding: 14,
    alignItems: 'center',
    marginBottom: 16,
  },
  buttonDisabled: {
    backgroundColor: '#6b7280',
  },
  buttonText: {
    color: '#ffffff',
    fontSize: 16,
    fontWeight: '600',
  },
  dangerButton: {
    backgroundColor: '#ef4444',
    borderRadius: 8,
    padding: 14,
    alignItems: 'center',
    marginBottom: 16,
  },
  infoButton: {
    backgroundColor: '#6b7280',
    borderRadius: 8,
    padding: 14,
    alignItems: 'center',
    marginBottom: 16,
  },
  resultContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
    borderRadius: 8,
    marginBottom: 16,
  },
  successContainer: {
    backgroundColor: 'rgba(16, 185, 129, 0.1)',
  },
  errorContainer: {
    backgroundColor: 'rgba(239, 68, 68, 0.1)',
  },
  resultText: {
    marginLeft: 8,
    fontSize: 14,
  },
  successText: {
    color: '#10b981',
  },
  errorText: {
    color: '#ef4444',
  },
  infoContainer: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 12,
  },
  infoLabel: {
    fontSize: 14,
    color: '#9ca3af',
  },
  infoValue: {
    fontSize: 14,
    color: '#ffffff',
    fontWeight: '600',
  },
  switchContainer: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 12,
  },
  switchLabel: {
    fontSize: 14,
    color: '#9ca3af',
  },
  debugContainer: {
    backgroundColor: '#2a2a2a',
    borderRadius: 8,
    padding: 12,
    marginTop: 8,
  },
  debugTitle: {
    fontSize: 16,
    fontWeight: 'bold',
    color: '#ffffff',
    marginBottom: 12,
  },
  debugItem: {
    flexDirection: 'row',
    marginBottom: 8,
  },
  debugLabel: {
    fontSize: 12,
    color: '#9ca3af',
    width: 120,
  },
  debugValue: {
    fontSize: 12,
    color: '#ffffff',
    flex: 1,
  },
  statusContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 8,
    marginBottom: 12,
  },
  statusLabel: {
    fontSize: 14,
    color: '#9ca3af',
    marginRight: 8,
  },
  statusIndicatorContainer: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  statusDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginRight: 6,
  },
  statusDotConnected: {
    backgroundColor: '#10b981',
  },
  statusDotDisconnected: {
    backgroundColor: '#ef4444',
  },
  statusText: {
    fontSize: 14,
    color: '#ffffff',
  },
});