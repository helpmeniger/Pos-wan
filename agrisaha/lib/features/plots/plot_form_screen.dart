import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../core/validation/validators.dart';
import '../../data/models/enums.dart';
import '../../data/models/plot.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';
import '../profile/niger_regions.dart';

/// Création ou modification d'une parcelle. Retourne l'identifiant de la
/// parcelle enregistrée via `context.pop(id)`.
class PlotFormScreen extends ConsumerWidget {
  const PlotFormScreen({super.key, this.plotId});

  final String? plotId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (plotId == null) {
      final profile = ref.watch(profileProvider);
      return AsyncValueView(
        isPage: true,
        value: profile,
        data: (profile) => _PlotForm(
          defaultRegion: profile?.region,
          defaultCommune: profile?.commune,
          defaultVillage: profile?.village,
        ),
      );
    }
    final plot = ref.watch(plotProvider(plotId!));
    return AsyncValueView<Plot?>(
      isPage: true,
      value: plot,
      data: (plot) => plot == null
          ? Scaffold(
              appBar: AppBar(),
              body: const ErrorState(message: 'Cette parcelle n’existe plus.'),
            )
          : _PlotForm(existing: plot),
    );
  }
}

class _PlotForm extends ConsumerStatefulWidget {
  const _PlotForm({
    this.existing,
    this.defaultRegion,
    this.defaultCommune,
    this.defaultVillage,
  });

  final Plot? existing;
  final String? defaultRegion;
  final String? defaultCommune;
  final String? defaultVillage;

  @override
  ConsumerState<_PlotForm> createState() => _PlotFormState();
}

class _PlotFormState extends ConsumerState<_PlotForm> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.existing?.name);
  late final _area = TextEditingController(
    text: widget.existing == null
        ? ''
        : Formatters.number(
            widget.existing!.area,
            maxDecimals: 4,
          ).replaceAll(RegExp(r'\s'), ''),
  );
  late final _commune = TextEditingController(
    text: widget.existing?.commune ?? widget.defaultCommune,
  );
  late final _village = TextEditingController(
    text: widget.existing?.village ?? widget.defaultVillage,
  );
  late final _notes = TextEditingController(text: widget.existing?.notes);
  late AreaUnit _unit = widget.existing?.areaUnit ?? AreaUnit.hectare;
  late SoilType _soil = widget.existing?.soilType ?? SoilType.inconnu;
  late String? _region = widget.existing?.region ?? widget.defaultRegion;
  bool _saving = false;

  @override
  void dispose() {
    for (final c in [_name, _area, _commune, _village, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.existing != null;
    final hasLocalUnit =
        ref.watch(profileProvider).value?.localAreaUnitHectares != null;
    return Scaffold(
      appBar: AppBar(
        title: Text(editing ? 'Modifier la parcelle' : 'Nouvelle parcelle'),
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(
                labelText: 'Nom de la parcelle *',
                hintText: 'Ex. Champ du bas-fond',
              ),
              textCapitalization: TextCapitalization.sentences,
              validator: (v) =>
                  Validators.requiredText(v, field: 'Le nom de la parcelle'),
            ),
            formGap,
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _area,
                    decoration: const InputDecoration(
                      labelText: 'Superficie *',
                    ),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    validator: (v) =>
                        Validators.positiveNumber(v, field: 'La superficie'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: SelectField<AreaUnit>(
                    label: 'Unité',
                    value: _unit,
                    items: AreaUnit.values,
                    itemLabel: (u) => u.label,
                    onChanged: (u) => setState(() => _unit = u ?? _unit),
                  ),
                ),
              ],
            ),
            if (_unit == AreaUnit.local && !hasLocalUnit) ...[
              const SizedBox(height: 8),
              const InfoBanner(
                message:
                    'Définissez l’équivalence de votre unité locale '
                    'en hectares dans le profil pour calculer les rendements.',
              ),
            ],
            formGap,
            SelectField<String>(
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
              label: 'Type de sol',
              value: _soil,
              items: SoilType.values,
              itemLabel: (s) => s.label,
              onChanged: (s) => setState(() => _soil = s ?? _soil),
            ),
            formGap,
            TextFormField(
              controller: _notes,
              decoration: const InputDecoration(labelText: 'Remarques'),
              maxLines: 3,
              validator: (v) =>
                  Validators.optionalText(v, max: Validators.maxNotesLength),
            ),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: _saving ? null : _save,
              icon: const Icon(Icons.save),
              label: Text(_saving ? 'Enregistrement…' : 'Enregistrer'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final repo = ref.read(plotRepositoryProvider);
    final area = Formatters.parseDecimal(_area.text)!;
    String? savedId;
    final ok = await runGuarded(context, () async {
      final existing = widget.existing;
      if (existing == null) {
        final plot = await repo.create(
          name: _name.text,
          area: area,
          areaUnit: _unit,
          region: _region,
          commune: _commune.text,
          village: _village.text,
          soilType: _soil,
          notes: _notes.text,
        );
        savedId = plot.id;
      } else {
        await repo.update(
          Plot(
            id: existing.id,
            name: _name.text.trim(),
            area: area,
            areaUnit: _unit,
            region: _region,
            commune: _commune.text.trim().isEmpty ? null : _commune.text.trim(),
            village: _village.text.trim().isEmpty ? null : _village.text.trim(),
            soilType: _soil,
            notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
            isDemo: existing.isDemo,
            createdAt: existing.createdAt,
            updatedAt: existing.updatedAt,
          ),
        );
        savedId = existing.id;
      }
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Parcelle enregistrée.');
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) context.pop(savedId);
  }
}
