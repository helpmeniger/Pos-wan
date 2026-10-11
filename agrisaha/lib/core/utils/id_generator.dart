import 'package:uuid/uuid.dart';

/// Génère des identifiants uniques (UUID v4) utilisables pour la
/// synchronisation future sans risque de collision entre appareils.
class IdGenerator {
  const IdGenerator._();

  static const _uuid = Uuid();

  static String newId() => _uuid.v4();
}
