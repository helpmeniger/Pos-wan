import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../core/validation/validators.dart';
import '../../data/models/enums.dart';
import '../../data/models/farm_profile.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';
import 'niger_regions.dart';

class ProfileFormScreen extends ConsumerWidget {
  const ProfileFormScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(profileProvider);
    return AsyncValueView<FarmProfile?>(
      isPage: true,
      value: profile,
      data: (profile) => _ProfileForm(existing: profile),
    );
  }
}

class _ProfileForm extends ConsumerStatefulWidget {
  const _ProfileForm({required this.existing});

  final FarmProfile? existing;

  @override
  ConsumerState<_ProfileForm> createState() => _ProfileFormState();
}

class _ProfileFormState extends ConsumerState<_ProfileForm> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.existing?.displayName);
  late final _farmName = TextEditingController(text: widget.existing?.farmName);
  late final _commune = TextEditingController(text: widget.existing?.commune);
  late final _village = TextEditingController(text: widget.existing?.village);
  late final _totalArea = TextEditingController(
    text: _numberText(widget.existing?.totalArea),
  );
  late final _localLabel = TextEditingController(
    text: widget.existing?.localAreaUnitLabel,
  );
  late final _localHectares = TextEditingController(
    text: _numberText(widget.existing?.localAreaUnitHectares),
  );
  late String? _region = widget.existing?.region;
  late AppLanguage _language = widget.existing?.language ?? AppLanguage.fr;
  late AreaUnit _totalUnit = widget.existing?.totalAreaUnit ?? AreaUnit.hectare;
  late ProductionMode? _mode = widget.existing?.productionMode;
  late final Set<CropType> _crops = {...?widget.existing?.cropTypes};
  bool _saving = false;

  static String _numberText(double? value) => value == null
      ? ''
      : Formatters.number(value, maxDecimals: 4).replaceAll(RegExp(r'\s'), '');

  @override
  void dispose() {
    for (final c in [
      _name,
      _farmName,
      _commune,
      _village,
      _totalArea,
      _localLabel,
      _localHectares,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mon profil agricole')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const InfoBanner(
              message:
                  'Seules les informations utiles au suivi de vos '
                  'cultures sont demandées. Elles restent sur ce téléphone.',
            ),
            formGap,
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(
                labelText: 'Nom ou pseudonyme *',
              ),
              textCapitalization: TextCapitalization.words,
              validator: (v) =>
                  Validators.requiredText(v, field: 'Le nom ou pseudonyme'),
            ),
            formGap,
            TextFormField(
              controller: _farmName,
              decoration: const InputDecoration(
                labelText: 'Nom de l’exploitation',
              ),
              validator: Validators.optionalText,
            ),
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
            SelectField<AppLanguage>(
              label: 'Langue préférée',
              value: _language,
              items: AppLanguage.values,
              itemLabel: (l) => l.label,
              helperText:
                  'L’interface est en français. Les traductions en '
                  'haoussa et zarma sont en préparation.',
              onChanged: (l) => setState(() => _language = l ?? _language),
            ),
            formGap,
            Text(
              'Cultures pratiquées',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                for (final crop in CropType.values)
                  FilterChip(
                    label: Text(crop.label),
                    selected: _crops.contains(crop),
                    onSelected: (selected) => setState(() {
                      selected ? _crops.add(crop) : _crops.remove(crop);
                    }),
                  ),
              ],
            ),
            formGap,
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _totalArea,
                    decoration: const InputDecoration(
                      labelText: 'Superficie totale',
                    ),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    validator: (v) =>
                        Validators.optionalPositiveNumber(v, allowZero: false),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: SelectField<AreaUnit>(
                    label: 'Unité',
                    value: _totalUnit,
                    items: AreaUnit.values,
                    itemLabel: (u) => u.label,
                    onChanged: (u) =>
                        setState(() => _totalUnit = u ?? _totalUnit),
                  ),
                ),
              ],
            ),
            formGap,
            SelectField<ProductionMode>(
              label: 'Mode de production',
              value: _mode,
              items: ProductionMode.values,
              itemLabel: (m) => m.label,
              onChanged: (m) => setState(() => _mode = m),
            ),
            formGap,
            Text(
              'Unité de surface locale (facultatif)',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const SizedBox(height: 8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _localLabel,
                    decoration: const InputDecoration(
                      labelText: 'Nom',
                      hintText: 'Ex. corde',
                    ),
                    validator: Validators.optionalText,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    controller: _localHectares,
                    decoration: const InputDecoration(
                      labelText: 'Équivalent en ha',
                    ),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    validator: (v) {
                      final error = Validators.optionalPositiveNumber(
                        v,
                        allowZero: false,
                      );
                      if (error != null) return error;
                      final hasLabel = _localLabel.text.trim().isNotEmpty;
                      final hasValue = (v ?? '').trim().isNotEmpty;
                      if (hasLabel != hasValue) {
                        return 'Indiquez le nom et l’équivalent.';
                      }
                      return null;
                    },
                  ),
                ),
              ],
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
    final ok = await runGuarded(context, () async {
      await ref
          .read(profileRepositoryProvider)
          .save(
            displayName: _name.text,
            farmName: _farmName.text,
            region: _region,
            commune: _commune.text,
            village: _village.text,
            language: _language,
            cropTypes: CropType.values.where(_crops.contains).toList(),
            totalArea: Formatters.parseDecimal(_totalArea.text),
            totalAreaUnit: _totalUnit,
            productionMode: _mode,
            localAreaUnitLabel: _localLabel.text,
            localAreaUnitHectares: Formatters.parseDecimal(_localHectares.text),
            notificationsEnabled: widget.existing?.notificationsEnabled ?? true,
          );
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Profil enregistré.');
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) context.pop();
  }
}
