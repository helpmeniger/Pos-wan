import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/errors/app_exception.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../core/validation/validators.dart';
import '../../data/models/crop_variety.dart';
import '../../data/models/enums.dart';
import '../../data/models/plot.dart';
import '../../data/repositories/crop_repository.dart';
import '../../domain/harvest_estimator.dart';
import '../../shared/services/photo_service.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';
import '../profile/niger_regions.dart';

final varietiesProvider = FutureProvider.family<List<CropVariety>, CropType>((
  ref,
  type,
) {
  ref.watch(dataRevisionProvider);
  return ref.watch(varietyRepositoryProvider).getForCrop(type);
});

final observedCycleProvider =
    FutureProvider.family<ObservedCycle?, (CropType, String?)>((ref, key) {
      ref.watch(dataRevisionProvider);
      return ref.watch(cropRepositoryProvider).observedCycle(key.$1, key.$2);
    });

/// Valeur spéciale de la liste des variétés : variété non listée/inconnue.
const _otherVarietyId = '__autre__';

class CropFormScreen extends ConsumerStatefulWidget {
  const CropFormScreen({super.key, this.initialPlotId});

  final String? initialPlotId;

  @override
  ConsumerState<CropFormScreen> createState() => _CropFormScreenState();
}

class _CropFormScreenState extends ConsumerState<CropFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _customCropName = TextEditingController();
  final _varietyName = TextEditingController();
  final _cycleDays = TextEditingController();
  final _area = TextEditingController();
  final _commune = TextEditingController();
  final _village = TextEditingController();
  final _notes = TextEditingController();

  String? _plotId;
  CropType _cropType = CropType.mil;
  String _varietyId = _otherVarietyId;
  SeedType _seedType = SeedType.traditionnelle;
  DateTime? _sowingDate;
  AreaUnit _areaUnit = AreaUnit.hectare;
  String? _region;
  SoilType _soilType = SoilType.inconnu;
  IrrigationMode _irrigation = IrrigationMode.pluvial;
  DateTime? _manualHarvestDate;
  bool _rememberVariety = false;
  String? _photoPath;
  bool _saving = false;
  bool _plotDefaultsApplied = false;

  @override
  void initState() {
    super.initState();
    _plotId = widget.initialPlotId;
    _sowingDate = ref.read(todayProvider);
    _cycleDays.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    for (final c in [
      _customCropName,
      _varietyName,
      _cycleDays,
      _area,
      _commune,
      _village,
      _notes,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  void _applyPlotDefaults(Plot plot) {
    _region = plot.region;
    _commune.text = plot.commune ?? '';
    _village.text = plot.village ?? '';
    _soilType = plot.soilType;
    _areaUnit = plot.areaUnit;
    if (_area.text.isEmpty) {
      _area.text = Formatters.number(
        plot.area,
        maxDecimals: 4,
      ).replaceAll(RegExp(r'\s'), '');
    }
  }

  CropVariety? _selectedVariety(List<CropVariety> varieties) {
    for (final v in varieties) {
      if (v.id == _varietyId) return v;
    }
    return null;
  }

  ({HarvestEstimate? estimate, String? error}) _computeEstimate(
    CropVariety? variety,
  ) {
    final sowing = _sowingDate;
    if (sowing == null) return (estimate: null, error: null);
    try {
      final days = variety == null
          ? int.tryParse(_cycleDays.text.trim())
          : null;
      final validDays =
          days != null && Validators.cycleDays(_cycleDays.text) == null
          ? days
          : null;
      return (
        estimate: HarvestEstimator.estimate(
          sowingDate: sowing,
          cropType: _cropType,
          variety: variety,
          customCycleDays: validDays,
          manualHarvestDate: _manualHarvestDate,
        ),
        error: null,
      );
    } on AppException catch (e) {
      return (estimate: null, error: e.userMessage);
    }
  }

  @override
  Widget build(BuildContext context) {
    final plotsValue = ref.watch(plotsProvider);
    final varieties =
        ref.watch(varietiesProvider(_cropType)).value ?? const <CropVariety>[];
    final variety = _selectedVariety(varieties);
    final result = _computeEstimate(variety);
    final today = ref.watch(todayProvider);
    final observed = ref
        .watch(
          observedCycleProvider((
            _cropType,
            variety?.name ?? _varietyName.text.trim(),
          )),
        )
        .value;

    return Scaffold(
      appBar: AppBar(title: const Text('Nouvelle culture')),
      body: AsyncValueView<List<Plot>>(
        value: plotsValue,
        onRetry: () => ref.invalidate(plotsProvider),
        data: (plots) {
          if (plots.isEmpty) {
            return EmptyState(
              icon: Icons.crop_square,
              title: 'Ajoutez d’abord une parcelle',
              message: 'Chaque culture est rattachée à une parcelle.',
              actionLabel: 'Créer une parcelle',
              onAction: () => _createPlot(),
            );
          }
          if (!plots.any((p) => p.id == _plotId)) {
            _plotId = plots.length == 1 ? plots.first.id : null;
            _plotDefaultsApplied = false;
          }
          if (!_plotDefaultsApplied && _plotId != null) {
            _applyPlotDefaults(plots.firstWhere((p) => p.id == _plotId));
            _plotDefaultsApplied = true;
          }
          return Form(
            key: _formKey,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _section('Parcelle'),
                Row(
                  children: [
                    Expanded(
                      child: SelectField<String>(
                        key: ValueKey('plot-$_plotId-${plots.length}'),
                        label: 'Parcelle *',
                        value: _plotId,
                        items: plots.map((p) => p.id).toList(),
                        itemLabel: (id) =>
                            plots.firstWhere((p) => p.id == id).name,
                        validator: (v) =>
                            v == null ? 'Choisissez une parcelle.' : null,
                        onChanged: (id) => setState(() {
                          _plotId = id;
                          if (id != null) {
                            _area.clear();
                            _applyPlotDefaults(
                              plots.firstWhere((p) => p.id == id),
                            );
                          }
                        }),
                      ),
                    ),
                    IconButton.filledTonal(
                      tooltip: 'Nouvelle parcelle',
                      onPressed: _createPlot,
                      icon: const Icon(Icons.add),
                    ),
                  ],
                ),
                _section('Culture et semence'),
                SelectField<CropType>(
                  label: 'Culture *',
                  value: _cropType,
                  items: CropType.values,
                  itemLabel: (c) => c.label,
                  onChanged: (c) => setState(() {
                    _cropType = c ?? _cropType;
                    _varietyId = _otherVarietyId;
                    _manualHarvestDate = null;
                    if (_cropType.group == CropGroup.vegetable) {
                      _irrigation = IrrigationMode.irrigation;
                    }
                  }),
                ),
                if (_cropType == CropType.autre) ...[
                  formGap,
                  TextFormField(
                    controller: _customCropName,
                    decoration: const InputDecoration(
                      labelText: 'Nom de la culture *',
                    ),
                    validator: (v) => Validators.requiredText(
                      v,
                      field: 'Le nom de la culture',
                    ),
                  ),
                ],
                formGap,
                SelectField<String>(
                  key: ValueKey('variety-$_cropType-${varieties.length}'),
                  label: 'Variété',
                  value: _varietyId,
                  items: [...varieties.map((v) => v.id), _otherVarietyId],
                  itemLabel: (id) {
                    if (id == _otherVarietyId) {
                      return 'Autre variété / inconnue';
                    }
                    final v = varieties.firstWhere((v) => v.id == id);
                    return '${v.name} (≈ ${v.typicalCycleDays} j)';
                  },
                  onChanged: (id) => setState(() {
                    _varietyId = id ?? _otherVarietyId;
                    _manualHarvestDate = null;
                  }),
                ),
                if (variety == null) ...[
                  formGap,
                  TextFormField(
                    controller: _varietyName,
                    decoration: const InputDecoration(
                      labelText: 'Nom de la variété (si connu)',
                    ),
                    validator: Validators.optionalText,
                    onChanged: (_) => setState(() {}),
                  ),
                  formGap,
                  TextFormField(
                    controller: _cycleDays,
                    decoration: const InputDecoration(
                      labelText: 'Durée du cycle connue (jours)',
                      helperText:
                          'Facultatif : durée semis → récolte indiquée '
                          'sur le sac de semence ou par votre conseiller.',
                      helperMaxLines: 3,
                    ),
                    keyboardType: TextInputType.number,
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? null
                        : Validators.cycleDays(v),
                  ),
                  if (_varietyName.text.trim().isNotEmpty &&
                      Validators.cycleDays(_cycleDays.text) == null)
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _rememberVariety,
                      onChanged: (v) =>
                          setState(() => _rememberVariety = v ?? false),
                      title: const Text(
                        'Mémoriser cette variété et sa durée pour les '
                        'prochains semis',
                      ),
                    ),
                ],
                formGap,
                SelectField<SeedType>(
                  label: 'Type de semence',
                  value: _seedType,
                  items: SeedType.values,
                  itemLabel: (s) => s.label,
                  onChanged: (s) => setState(() => _seedType = s ?? _seedType),
                ),
                _section('Semis et surface'),
                DateField(
                  label: _cropType.isUsuallyTransplanted
                      ? 'Date de repiquage (ou de semis) *'
                      : 'Date de semis *',
                  value: _sowingDate,
                  firstDate: DateOnly.addDays(today, -730),
                  lastDate: DateOnly.addDays(today, 365),
                  helperText: 'Une date future permet de planifier un semis.',
                  validator: (d) => Validators.sowingDate(d, today),
                  onChanged: (d) => setState(() {
                    _sowingDate = d;
                    _manualHarvestDate = null;
                  }),
                ),
                formGap,
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: TextFormField(
                        controller: _area,
                        decoration: const InputDecoration(
                          labelText: 'Superficie cultivée *',
                        ),
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        validator: (v) => Validators.positiveNumber(
                          v,
                          field: 'La superficie',
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: SelectField<AreaUnit>(
                        key: ValueKey('unit-$_areaUnit'),
                        label: 'Unité',
                        value: _areaUnit,
                        items: AreaUnit.values,
                        itemLabel: (u) => u.label,
                        onChanged: (u) =>
                            setState(() => _areaUnit = u ?? _areaUnit),
                      ),
                    ),
                  ],
                ),
                formGap,
                SelectField<IrrigationMode>(
                  key: ValueKey('irrigation-$_irrigation'),
                  label: 'Mode d’irrigation',
                  value: _irrigation,
                  items: IrrigationMode.values,
                  itemLabel: (m) => m.label,
                  onChanged: (m) =>
                      setState(() => _irrigation = m ?? _irrigation),
                ),
                _section('Localisation et sol'),
                SelectField<String>(
                  key: ValueKey('region-$_region'),
                  label: 'Région',
                  value: _region,
                  items: nigerRegions,
                  itemLabel: (r) => r,
                  onChanged: (r) => setState(() => _region = r),
                ),
                formGap,
                TextFormField(
                  controller: _commune,
                  decoration: const InputDecoration(
                    labelText: 'Département ou commune',
                  ),
                  validator: Validators.optionalText,
                ),
                formGap,
                TextFormField(
                  controller: _village,
                  decoration: const InputDecoration(labelText: 'Village'),
                  validator: Validators.optionalText,
                ),
                formGap,
                SelectField<SoilType>(
                  key: ValueKey('soil-$_soilType'),
                  label: 'Type de sol',
                  value: _soilType,
                  items: SoilType.values,
                  itemLabel: (s) => s.label,
                  onChanged: (s) => setState(() => _soilType = s ?? _soilType),
                ),
                _section('Récolte estimée'),
                _EstimateCard(
                  estimate: result.estimate,
                  error: result.error,
                  observed: observed,
                  onCorrect: _sowingDate == null ? null : _pickManualDate,
                  onResetManual: _manualHarvestDate == null
                      ? null
                      : () => setState(() => _manualHarvestDate = null),
                ),
                _section('Observations et photo'),
                TextFormField(
                  controller: _notes,
                  decoration: const InputDecoration(labelText: 'Observations'),
                  maxLines: 3,
                  validator: (v) => Validators.optionalText(
                    v,
                    max: Validators.maxNotesLength,
                  ),
                ),
                formGap,
                _PhotoPicker(
                  path: _photoPath,
                  onPick: () async {
                    final path = await runPhotoPicker(context);
                    if (path != null) setState(() => _photoPath = path);
                  },
                  onRemove: () => setState(() => _photoPath = null),
                ),
                const SizedBox(height: 24),
                FilledButton.icon(
                  onPressed: _saving || result.estimate == null
                      ? null
                      : () => _save(result.estimate!, variety),
                  icon: const Icon(Icons.save),
                  label: Text(
                    _saving
                        ? 'Enregistrement…'
                        : 'Enregistrer et créer le calendrier',
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _section(String title) => Padding(
    padding: const EdgeInsets.only(top: 24, bottom: 12),
    child: Text(
      title,
      style: Theme.of(context).textTheme.titleMedium
          ?.copyWith(color: AppColors.darkGreen),
    ),
  );

  Future<void> _createPlot() async {
    final id = await context.push<String>(Routes.newPlot);
    if (id != null && mounted) {
      setState(() {
        _plotId = id;
        _plotDefaultsApplied = false;
        _area.clear();
      });
    }
  }

  Future<void> _pickManualDate() async {
    final sowing = _sowingDate!;
    final picked = await showDatePicker(
      context: context,
      initialDate: _manualHarvestDate ?? DateOnly.addDays(sowing, 90),
      firstDate: DateOnly.addDays(sowing, 1),
      lastDate: DateOnly.addDays(sowing, HarvestEstimator.maxCycleDays),
      locale: const Locale('fr', 'FR'),
      helpText: 'Date de récolte estimée',
    );
    if (picked != null) {
      setState(() => _manualHarvestDate = DateOnly.of(picked));
    }
  }

  Future<void> _save(HarvestEstimate estimate, CropVariety? variety) async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final converter = await ref.read(areaConverterProvider.future);
    if (!mounted) return;
    String? cropId;
    final ok = await runGuarded(context, () async {
      var varietyRef = variety?.id;
      final varietyName = variety?.name ?? _varietyName.text.trim();
      if (variety == null &&
          _rememberVariety &&
          varietyName.isNotEmpty &&
          Validators.cycleDays(_cycleDays.text) == null) {
        final saved = await ref
            .read(varietyRepositoryProvider)
            .addUserVariety(
              cropType: _cropType,
              name: varietyName,
              cycleDays: int.parse(_cycleDays.text.trim()),
            );
        varietyRef = saved.id;
      }
      final crop = await ref
          .read(cropRepositoryProvider)
          .create(
            CropDraft(
              plotId: _plotId!,
              cropType: _cropType,
              customCropName: _customCropName.text,
              varietyName: varietyName,
              varietyRef: varietyRef,
              seedType: _seedType,
              area: Formatters.parseDecimal(_area.text)!,
              areaUnit: _areaUnit,
              region: _region,
              commune: _commune.text,
              village: _village.text,
              soilType: _soilType,
              irrigationMode: _irrigation,
              estimate: estimate,
              notes: _notes.text,
              photoPath: _photoPath,
            ),
            areaConverter: converter,
          );
      cropId = crop.id;
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Culture enregistrée. Calendrier agricole créé.');
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok && cropId != null) {
      context.pushReplacement(Routes.crop(cropId!));
    }
  }
}

class _EstimateCard extends StatelessWidget {
  const _EstimateCard({
    required this.estimate,
    required this.error,
    required this.observed,
    required this.onCorrect,
    required this.onResetManual,
  });

  final HarvestEstimate? estimate;
  final String? error;
  final ObservedCycle? observed;
  final VoidCallback? onCorrect;
  final VoidCallback? onResetManual;

  @override
  Widget build(BuildContext context) {
    final e = estimate;
    return SectionCard(
      color: AppColors.lightYellow,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (error != null)
            Text(error!, style: const TextStyle(color: AppColors.danger))
          else if (e == null)
            const Text(
              'Aucune durée de cycle documentée pour cette culture. '
              'Indiquez la durée du cycle en jours ou choisissez la date '
              'de récolte estimée.',
            )
          else ...[
            Text(
              Formatters.longDate(e.estimatedDate),
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text('Semis + ${e.maturationDays} jours'),
            if (e.earliestDate != null &&
                e.latestDate != null &&
                e.minDays != e.maxDays)
              Text(
                'Fenêtre probable : du ${Formatters.date(e.earliestDate!)} '
                'au ${Formatters.date(e.latestDate!)}',
              ),
            const SizedBox(height: 8),
            InfoRow(label: 'Source', value: e.source.label),
            InfoRow(label: 'Confiance', value: e.confidence.label),
            if (e.sourceNote != null)
              Text(e.sourceNote!, style: Theme.of(context).textTheme.bodySmall),
            if (e.isGeneric) ...[
              const SizedBox(height: 8),
              const InfoBanner(
                message:
                    'Estimation générique : la variété exacte est '
                    'inconnue. Vous ou votre conseiller pouvez corriger la '
                    'date.',
              ),
            ],
          ],
          if (observed != null) ...[
            const SizedBox(height: 8),
            Text(
              'Vos récoltes précédentes : cycle moyen observé de '
              '${observed!.averageDays} jours (${observed!.sampleSize} récolte(s)).',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
          const SizedBox(height: 8),
          const Text(
            'La date réelle peut varier selon la variété, la pluie ou '
            'l’irrigation, le sol et les pratiques culturales.',
            style: TextStyle(fontSize: 13, fontStyle: FontStyle.italic),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: onCorrect,
                icon: const Icon(Icons.edit_calendar),
                label: const Text('Saisir / corriger la date'),
              ),
              if (onResetManual != null)
                TextButton(
                  onPressed: onResetManual,
                  child: const Text('Revenir au calcul automatique'),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Ouvre le choix de photo (appareil ou galerie) avec explication.
Future<String?> runPhotoPicker(BuildContext context) async {
  try {
    return await PhotoService().pickWithConsent(context);
  } catch (error, stack) {
    ErrorHandler.log(error, stack);
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Impossible d’ajouter la photo. Vérifiez que l’accès à '
            'l’appareil photo ou aux images est autorisé.',
          ),
        ),
      );
    }
    return null;
  }
}

class _PhotoPicker extends StatelessWidget {
  const _PhotoPicker({
    required this.path,
    required this.onPick,
    required this.onRemove,
  });

  final String? path;
  final VoidCallback onPick;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    if (path == null) {
      return OutlinedButton.icon(
        onPressed: onPick,
        icon: const Icon(Icons.add_a_photo),
        label: const Text('Ajouter une photo (facultatif)'),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(14),
          child: Image.file(
            File(path!),
            height: 180,
            fit: BoxFit.cover,
            errorBuilder: (_, _, _) => const Text('Photo introuvable.'),
          ),
        ),
        TextButton.icon(
          onPressed: onRemove,
          icon: const Icon(Icons.delete_outline),
          label: const Text('Retirer la photo'),
        ),
      ],
    );
  }
}
