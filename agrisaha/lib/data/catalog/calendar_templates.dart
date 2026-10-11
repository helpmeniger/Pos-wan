import '../models/enums.dart';

/// Modèle de tâche agricole servant à construire le calendrier d'une culture.
///
/// Les décalages sont exprimés en jours par rapport au semis
/// ([TaskAnchor.sowing]) ou à la date estimée de récolte
/// ([TaskAnchor.harvest]). Les descriptions restent volontairement
/// générales : aucun produit ni dose n'est recommandé, ces choix devant être
/// validés par un technicien agricole.
class TaskTemplate {
  const TaskTemplate({
    required this.key,
    required this.title,
    required this.description,
    required this.anchor,
    required this.offsetStart,
    required this.offsetEnd,
    this.groups,
    this.cropTypes,
    this.requiresIrrigation = false,
    this.repeatEveryDays,
    this.repeatUntilHarvestOffset,
  });

  final String key;
  final String title;
  final String description;
  final TaskAnchor anchor;
  final int offsetStart;
  final int offsetEnd;

  /// Familles concernées (toutes si `null`).
  final Set<CropGroup>? groups;

  /// Cultures concernées (prioritaire sur [groups] si renseigné).
  final Set<CropType>? cropTypes;

  /// Tâche générée uniquement pour les cultures irriguées ou mixtes.
  final bool requiresIrrigation;

  /// Tâche répétée tous les N jours…
  final int? repeatEveryDays;

  /// …jusqu'à la date estimée de récolte + ce décalage (négatif = avant).
  final int? repeatUntilHarvestOffset;

  bool appliesTo(CropType type, IrrigationMode irrigation) {
    if (requiresIrrigation && !irrigation.usesIrrigation) return false;
    if (cropTypes != null) return cropTypes!.contains(type);
    if (groups != null) return groups!.contains(type.group);
    return true;
  }
}

/// Recommandations génériques de conduite des cultures (statut : à valider
/// par un conseiller agricole — voir `docs/CATALOGUE_AGRONOMIQUE.md`).
class CalendarTemplates {
  const CalendarTemplates._();

  static const _fieldCrops = {
    CropGroup.cereal,
    CropGroup.legume,
    CropGroup.rice,
    CropGroup.other,
  };

  static const List<TaskTemplate> all = [
    TaskTemplate(
      key: 'soil_preparation',
      title: 'Préparation du sol',
      description:
          'Nettoyer la parcelle, apporter la fumure organique '
          'disponible et préparer le sol ou les planches avant le semis.',
      anchor: TaskAnchor.sowing,
      offsetStart: -14,
      offsetEnd: -1,
    ),
    TaskTemplate(
      key: 'sowing',
      title: 'Semis / repiquage',
      description:
          'Semer (ou repiquer) à la date prévue en respectant les '
          'écartements conseillés pour la variété.',
      anchor: TaskAnchor.sowing,
      offsetStart: 0,
      offsetEnd: 0,
    ),
    TaskTemplate(
      key: 'emergence_check',
      title: 'Contrôle de la levée',
      description:
          'Compter les poquets levés et repérer les manquants. '
          'Ressemer rapidement les vides si la saison le permet.',
      anchor: TaskAnchor.sowing,
      offsetStart: 5,
      offsetEnd: 10,
      groups: _fieldCrops,
    ),
    TaskTemplate(
      key: 'transplant_check',
      title: 'Contrôle de la reprise des plants',
      description:
          'Vérifier la reprise des plants repiqués et remplacer '
          'les plants morts.',
      anchor: TaskAnchor.sowing,
      offsetStart: 5,
      offsetEnd: 10,
      groups: {CropGroup.vegetable},
    ),
    TaskTemplate(
      key: 'thinning',
      title: 'Démariage',
      description:
          'Ne garder que le nombre de plants par poquet recommandé '
          'par votre conseiller agricole.',
      anchor: TaskAnchor.sowing,
      offsetStart: 15,
      offsetEnd: 20,
      cropTypes: {CropType.mil, CropType.sorgho},
    ),
    TaskTemplate(
      key: 'weeding_1',
      title: 'Premier sarclage / désherbage',
      description:
          'Éliminer les mauvaises herbes qui concurrencent la '
          'culture pour l’eau et les éléments nutritifs.',
      anchor: TaskAnchor.sowing,
      offsetStart: 15,
      offsetEnd: 25,
    ),
    TaskTemplate(
      key: 'fertilization',
      title: 'Fertilisation',
      description:
          'Apporter la fumure selon la recommandation validée par un '
          'technicien (type de fertilisant et dose à confirmer avec lui).',
      anchor: TaskAnchor.sowing,
      offsetStart: 15,
      offsetEnd: 30,
      groups: {CropGroup.cereal, CropGroup.rice, CropGroup.vegetable},
    ),
    TaskTemplate(
      key: 'weeding_2',
      title: 'Deuxième sarclage',
      description: 'Deuxième passage de désherbage ; buttage si nécessaire.',
      anchor: TaskAnchor.sowing,
      offsetStart: 35,
      offsetEnd: 45,
      groups: {CropGroup.cereal, CropGroup.legume, CropGroup.vegetable},
    ),
    TaskTemplate(
      key: 'pest_watch',
      title: 'Surveillance des ravageurs et maladies',
      description:
          'Parcourir la parcelle, observer feuilles, tiges, épis ou '
          'fruits. Noter tout symptôme dans le journal et consulter un '
          'technicien en cas de doute.',
      anchor: TaskAnchor.sowing,
      offsetStart: 14,
      offsetEnd: 17,
      repeatEveryDays: 14,
      repeatUntilHarvestOffset: -14,
    ),
    TaskTemplate(
      key: 'irrigation_check',
      title: 'Contrôle de l’humidité du sol et irrigation',
      description:
          'Vérifier l’humidité du sol à quelques centimètres de '
          'profondeur et irriguer si le sol est sec.',
      anchor: TaskAnchor.sowing,
      offsetStart: 3,
      offsetEnd: 5,
      requiresIrrigation: true,
      repeatEveryDays: 7,
      repeatUntilHarvestOffset: -10,
    ),
    TaskTemplate(
      key: 'maturity_watch',
      title: 'Surveillance de la maturation',
      description:
          'Observer la maturité (grains durs, gousses sèches, '
          'feuilles qui jaunissent, fruits colorés selon la culture).',
      anchor: TaskAnchor.harvest,
      offsetStart: -14,
      offsetEnd: -8,
    ),
    TaskTemplate(
      key: 'harvest_preparation',
      title: 'Préparation de la récolte',
      description:
          'Prévoir la main-d’œuvre, les outils, les sacs propres et '
          'une aire de séchage propre.',
      anchor: TaskAnchor.harvest,
      offsetStart: -7,
      offsetEnd: -1,
    ),
    TaskTemplate(
      key: 'harvest',
      title: 'Récolte',
      description:
          'Récolter à maturité puis enregistrer la récolte réelle '
          'dans l’application.',
      anchor: TaskAnchor.harvest,
      offsetStart: 0,
      offsetEnd: 7,
    ),
    TaskTemplate(
      key: 'drying_storage',
      title: 'Séchage et stockage',
      description:
          'Sécher la récolte à l’abri de la terre et de l’humidité, '
          'puis stocker dans des sacs propres (de préférence hermétiques) '
          'dans un lieu sec et aéré.',
      anchor: TaskAnchor.harvest,
      offsetStart: 1,
      offsetEnd: 14,
      groups: {CropGroup.cereal, CropGroup.legume, CropGroup.rice},
    ),
    TaskTemplate(
      key: 'sorting_storage',
      title: 'Tri et conservation',
      description:
          'Trier les produits abîmés et conserver le reste dans un '
          'endroit ombragé et ventilé.',
      anchor: TaskAnchor.harvest,
      offsetStart: 1,
      offsetEnd: 7,
      groups: {CropGroup.vegetable},
    ),
  ];
}
