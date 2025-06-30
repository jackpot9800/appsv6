import React, { useEffect, useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  FlatList,
  TouchableOpacity,
  RefreshControl,
  Alert,
  Dimensions,
  Image,
} from 'react-native';
import { router } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { Monitor, Play, Clock, WifiOff, CircleAlert as AlertCircle, RefreshCw, Settings } from 'lucide-react-native';
import { apiService, Presentation } from '@/services/ApiService';

const { width } = Dimensions.get('window');
const CARD_MARGIN = 8;
const NUM_COLUMNS = 3;
const CARD_WIDTH = (width - (CARD_MARGIN * (NUM_COLUMNS + 1) * 2)) / NUM_COLUMNS;

export default function PresentationsScreen() {
  const [presentations, setPresentations] = useState<Presentation[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    loadPresentations();
  }, []);

  const loadPresentations = async () => {
    try {
      setLoading(true);
      setError(null);
      
      console.log('=== LOADING PRESENTATIONS ===');
      console.log('Server URL:', apiService.getServerUrl());
      
      const data = await apiService.getPresentations();
      console.log('Presentations loaded successfully:', data.length);
      
      setPresentations(data);
    } catch (error) {
      console.error('=== ERROR LOADING PRESENTATIONS ===');
      console.error('Error details:', error);
      
      const errorMessage = error instanceof Error ? error.message : 'Erreur inconnue';
      setError(errorMessage);
      
      Alert.alert(
        'Erreur de connexion',
        `Impossible de charger les présentations:\n\n${errorMessage}`,
        [
          { text: 'Paramètres', onPress: () => router.push('/settings') },
          { text: 'Réessayer', onPress: loadPresentations },
        ]
      );
    } finally {
      setLoading(false);
    }
  };

  const onRefresh = async () => {
    setRefreshing(true);
    await loadPresentations();
    setRefreshing(false);
  };

  const playPresentation = (presentation: Presentation) => {
    console.log('Playing presentation:', presentation.id, presentation.name);
    router.push(`/presentation/${presentation.id}`);
  };

  const renderPresentationItem = ({ item }: { item: Presentation }) => (
    <TouchableOpacity
      style={styles.presentationCard}
      onPress={() => playPresentation(item)}
      activeOpacity={0.8}
    >
      <LinearGradient
        colors={['#4f46e5', '#7c3aed']}
        style={styles.cardGradient}
      >
        <View style={styles.cardContent}>
          <View style={styles.cardHeader}>
            <View style={styles.iconContainer}>
              <Monitor size={20} color="#ffffff" />
            </View>
            <Text style={styles.cardTitle} numberOfLines={2}>
              {item.name}
            </Text>
          </View>
          
          <View style={styles.cardFooter}>
            <View style={styles.metaItem}>
              <Monitor size={12} color="#ffffff" />
              <Text style={styles.metaText}>
                {item.slide_count} slide{item.slide_count > 1 ? 's' : ''}
              </Text>
            </View>
            
            <View style={styles.playButton}>
              <Play size={16} color="#ffffff" fill="#ffffff" />
            </View>
          </View>
        </View>
      </LinearGradient>
    </TouchableOpacity>
  );

  const renderEmptyState = () => (
    <View style={styles.emptyState}>
      {!apiService.getServerUrl() ? (
        <>
          <WifiOff size={64} color="#ef4444" />
          <Text style={styles.emptyTitle}>Serveur non configuré</Text>
          <Text style={styles.emptyMessage}>
            Configurez l'URL de votre serveur dans les paramètres.
          </Text>
          <TouchableOpacity
            style={styles.configButton}
            onPress={() => router.push('/settings')}
          >
            <Text style={styles.configButtonText}>Configurer</Text>
          </TouchableOpacity>
        </>
      ) : error ? (
        <>
          <AlertCircle size={64} color="#ef4444" />
          <Text style={styles.emptyTitle}>Erreur de chargement</Text>
          <Text style={styles.emptyMessage}>
            {error}
          </Text>
          <TouchableOpacity
            style={styles.configButton}
            onPress={loadPresentations}
          >
            <Text style={styles.configButtonText}>Réessayer</Text>
          </TouchableOpacity>
        </>
      ) : (
        <>
          <Monitor size={64} color="#6b7280" />
          <Text style={styles.emptyTitle}>Aucune présentation</Text>
          <Text style={styles.emptyMessage}>
            Aucune présentation disponible sur le serveur.
            Créez des présentations depuis votre plateforme web.
          </Text>
        </>
      )}
    </View>
  );

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.title}>Présentations</Text>
        <Text style={styles.subtitle}>
          {error ? 'Erreur de chargement' : 
           `${presentations.length} présentation${presentations.length > 1 ? 's' : ''} disponible${presentations.length > 1 ? 's' : ''}`}
        </Text>
        {apiService.getServerUrl() && (
          <Text style={styles.serverInfo}>
            Serveur: {apiService.getServerUrl()}
          </Text>
        )}
      </View>

      <FlatList
        data={presentations}
        renderItem={renderPresentationItem}
        keyExtractor={(item) => item.id.toString()}
        contentContainerStyle={styles.listContent}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor="#3b82f6"
          />
        }
        ListEmptyComponent={renderEmptyState}
        showsVerticalScrollIndicator={false}
        numColumns={NUM_COLUMNS}
        columnWrapperStyle={styles.columnWrapper}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0a0a0a',
  },
  header: {
    padding: 20,
    paddingBottom: 16,
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
    marginBottom: 4,
  },
  serverInfo: {
    fontSize: 12,
    color: '#6b7280',
    fontFamily: 'monospace',
  },
  listContent: {
    paddingHorizontal: 12,
    paddingBottom: 20,
  },
  columnWrapper: {
    justifyContent: 'flex-start',
    marginHorizontal: CARD_MARGIN,
  },
  presentationCard: {
    width: CARD_WIDTH,
    height: 180,
    margin: CARD_MARGIN,
    borderRadius: 12,
    overflow: 'hidden',
  },
  cardGradient: {
    flex: 1,
    borderRadius: 12,
  },
  cardContent: {
    flex: 1,
    padding: 12,
    justifyContent: 'space-between',
  },
  cardHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  iconContainer: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(255, 255, 255, 0.2)',
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 8,
  },
  cardTitle: {
    flex: 1,
    fontSize: 16,
    fontWeight: 'bold',
    color: '#ffffff',
    marginBottom: 4,
  },
  cardFooter: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  metaItem: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  metaText: {
    fontSize: 12,
    color: '#ffffff',
    marginLeft: 4,
    opacity: 0.8,
  },
  playButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(255, 255, 255, 0.2)',
    justifyContent: 'center',
    alignItems: 'center',
  },
  emptyState: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 80,
    paddingHorizontal: 40,
  },
  emptyTitle: {
    fontSize: 20,
    fontWeight: 'bold',
    color: '#ffffff',
    marginTop: 16,
    marginBottom: 8,
    textAlign: 'center',
  },
  emptyMessage: {
    fontSize: 14,
    color: '#9ca3af',
    textAlign: 'center',
    lineHeight: 20,
    marginBottom: 16,
  },
  configButton: {
    backgroundColor: '#3b82f6',
    borderRadius: 8,
    paddingHorizontal: 24,
    paddingVertical: 12,
    marginTop: 8,
  },
  configButtonText: {
    color: '#ffffff',
    fontSize: 16,
    fontWeight: '600',
  },
});