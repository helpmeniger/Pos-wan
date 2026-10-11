import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/id_generator.dart';
import '../models/enums.dart';
import '../models/farm_profile.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

/// Profil unique de l'appareil (utilisation locale sans compte).
class ProfileRepository {
  ProfileRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  Future<FarmProfile?> get() async {
    final rows = await _database.db.query('farm_profile', limit: 1);
    return rows.isEmpty ? null : FarmProfile.fromMap(rows.first);
  }

  Future<FarmProfile> save({
    required String displayName,
    String? farmName,
    String? region,
    String? commune,
    String? village,
    AppLanguage language = AppLanguage.fr,
    List<CropType> cropTypes = const [],
    double? totalArea,
    AreaUnit totalAreaUnit = AreaUnit.hectare,
    ProductionMode? productionMode,
    String? localAreaUnitLabel,
    double? localAreaUnitHectares,
    bool notificationsEnabled = true,
  }) async {
    if (displayName.trim().isEmpty) {
      throw const ValidationException('Indiquez un nom ou un pseudonyme.');
    }
    if (totalArea != null && totalArea <= 0) {
      throw const ValidationException(
        'La superficie totale doit être supérieure à zéro.',
      );
    }
    if (localAreaUnitHectares != null && localAreaUnitHectares <= 0) {
      throw const ValidationException(
        'L’équivalence de l’unité locale doit être supérieure à zéro.',
      );
    }
    final existing = await get();
    final now = _clock();
    final profile = FarmProfile(
      id: existing?.id ?? IdGenerator.newId(),
      displayName: displayName.trim(),
      farmName: cleanText(farmName),
      region: cleanText(region),
      commune: cleanText(commune),
      village: cleanText(village),
      language: language,
      cropTypes: cropTypes,
      totalArea: totalArea,
      totalAreaUnit: totalAreaUnit,
      productionMode: productionMode,
      localAreaUnitLabel: cleanText(localAreaUnitLabel),
      localAreaUnitHectares: localAreaUnitHectares,
      notificationsEnabled: notificationsEnabled,
      createdAt: existing?.createdAt ?? now,
      updatedAt: now,
    );
    await _database.db.transaction((txn) async {
      await txn.delete('farm_profile');
      await txn.insert('farm_profile', profile.toMap());
      await SyncOutbox.record(
        txn,
        entity: 'farm_profile',
        entityId: profile.id,
        operation: 'upsert',
      );
    });
    return profile;
  }

  /// Supprime uniquement les informations du profil (les cultures et
  /// récoltes restent sur le téléphone).
  Future<void> deleteProfile() async {
    await _database.db.delete('farm_profile');
  }
}
