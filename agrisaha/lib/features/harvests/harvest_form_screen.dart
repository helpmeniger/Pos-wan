import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../core/validation/validators.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../data/repositories/harvest_repository.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';

class HarvestFormScreen extends ConsumerWidget {
  const HarvestFormScreen({super.key, required this.cropId});

  final String cropId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final crop = ref.watch(cropProvider(cropId));
    return AsyncValueView<Crop?>(
      isPage: true,
      value: crop,
      data: (crop) => crop == null
          ? Scaffold(
              appBar: AppBar(),
              body: const ErrorState(message: 'Cette culture n’existe plus.'),
            )
          : _HarvestForm(crop: crop),
    );
  }
}

class _HarvestForm extends ConsumerStatefulWidget {
  const _HarvestForm({required this.crop});

  final Crop crop;

  @override
  ConsumerState<_HarvestForm> createState() => _HarvestFormState();
}

class _HarvestFormState extends ConsumerState<_HarvestForm> {
  final _formKey = GlobalKey<FormState>();
  final _quantity = TextEditingController();
  final _unitLabel = TextEditingController();
  final _kgPerUnit = TextEditingController();
  late final _area = TextEditingController(
    text: Formatters.number(
      widget.crop.area,
      maxDecimals: 4,
    ).replaceAll(RegExp(r'\s'), ''),
  );
  final _losses = TextEditingController();
  final _sold = TextEditingController();
  final _stored = TextEditingController();
  final _price = TextEditingController();
  final _notes = TextEditingController();
  late DateTime _date = _initialDate();
  HarvestUnit _unit = HarvestUnit.kg;
  late AreaUnit _areaUnit = widget.crop.areaUnit;
  bool _isFinal = true;
  bool _recordSale = true;
  bool _saving = false;

  DateTime _initialDate() {
    final today = ref.read(todayProvider);
    final estimated = widget.crop.estimatedHarvestDate;
    return estimated.isAfter(today) ? today : estimated;
  }

  @override
  void dispose() {
    for (final c in [
      _quantity,
      _unitLabel,
      _kgPerUnit,
      _area,
      _losses,
      _sold,
      _stored,
      _price,
      _notes,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _needsKgPerUnit =>
      _unit == HarvestUnit.sac || _unit == HarvestUnit.autre;

  String? _validateSplit(String? _) {
    final qty = Formatters.parseDecimal(_quantity.text) ?? 0;
    final sold = Formatters.parseDecimal(_sold.text) ?? 0;
    final stored = Formatters.parseDecimal(_stored.text) ?? 0;
    if (sold + stored > qty + 1e-9) {
      return 'Vendu + conservé dépasse la quantité récoltée.';
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final today = ref.watch(todayProvider);
    final crop = widget.crop;
    final firstDate = DateOnly.addDays(crop.sowingDate, 1);
    return Scaffold(
      appBar: AppBar(title: Text('Récolte — ${crop.cropLabel}')),
      body: firstDate.isAfter(today)
          ? const ErrorState(
              message:
                  'La culture n’est pas encore semée : la récolte ne '
                  'peut pas être enregistrée.',
            )
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  InfoBanner(
                    message:
                        'Date estimée : '
                        '${Formatters.date(crop.estimatedHarvestDate)}. '
                        'Saisissez la date réelle de récolte.',
                  ),
                  formGap,
                  DateField(
                    label: 'Date réelle de récolte *',
                    value: _date,
                    firstDate: firstDate,
                    lastDate: today,
                    validator: (d) =>
                        Validators.harvestDate(d, crop.sowingDate, today),
                    onChanged: (d) => setState(() => _date = d),
                  ),
                  formGap,
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: _quantity,
                          decoration: const InputDecoration(
                            labelText: 'Quantité récoltée *',
                          ),
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          validator: (v) => Validators.positiveNumber(
                            v,
                            field: 'La quantité',
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: SelectField<HarvestUnit>(
                          label: 'Unité',
                          value: _unit,
                          items: HarvestUnit.values,
                          itemLabel: (u) => u.label,
                          onChanged: (u) => setState(() => _unit = u ?? _unit),
                        ),
                      ),
                    ],
                  ),
                  if (_unit == HarvestUnit.autre) ...[
                    formGap,
                    TextFormField(
                      controller: _unitLabel,
                      decoration: const InputDecoration(
                        labelText: 'Nom de l’unité *',
                        hintText: 'Ex. tiya, botte, panier',
                      ),
                      validator: (v) => Validators.requiredText(
                        v,
                        field: 'Le nom de l’unité',
                      ),
                    ),
                  ],
                  if (_needsKgPerUnit) ...[
                    formGap,
                    TextFormField(
                      controller: _kgPerUnit,
                      decoration: const InputDecoration(
                        labelText: 'Poids d’une unité (kg)',
                        helperText:
                            'Ex. 100 pour un sac de 100 kg. Nécessaire '
                            'pour calculer le rendement.',
                        helperMaxLines: 2,
                      ),
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      validator: (v) => Validators.optionalPositiveNumber(
                        v,
                        allowZero: false,
                      ),
                    ),
                  ],
                  formGap,
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: _area,
                          decoration: const InputDecoration(
                            labelText: 'Superficie récoltée',
                          ),
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          validator: (v) => Validators.optionalPositiveNumber(
                            v,
                            allowZero: false,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: SelectField<AreaUnit>(
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
                  TextFormField(
                    controller: _losses,
                    decoration: InputDecoration(
                      labelText: 'Pertes estimées (${_unitName()})',
                    ),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    validator: Validators.optionalPositiveNumber,
                  ),
                  formGap,
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: _sold,
                          decoration: InputDecoration(
                            labelText: 'Quantité vendue (${_unitName()})',
                          ),
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          validator: (v) =>
                              Validators.optionalPositiveNumber(v) ??
                              _validateSplit(v),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: TextFormField(
                          controller: _stored,
                          decoration: InputDecoration(
                            labelText: 'Quantité conservée (${_unitName()})',
                          ),
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          validator: (v) =>
                              Validators.optionalPositiveNumber(v) ??
                              _validateSplit(v),
                        ),
                      ),
                    ],
                  ),
                  formGap,
                  TextFormField(
                    controller: _price,
                    decoration: InputDecoration(
                      labelText: 'Prix de vente (FCFA par ${_unitName()})',
                    ),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    validator: Validators.optionalPositiveNumber,
                  ),
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    value: _recordSale,
                    onChanged: (v) => setState(() => _recordSale = v ?? true),
                    title: const Text(
                      'Ajouter la vente aux recettes de l’exploitation',
                    ),
                  ),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: _isFinal,
                    onChanged: (v) => setState(() => _isFinal = v),
                    title: const Text('Récolte terminée'),
                    subtitle: const Text(
                      'Désactivez pour une récolte partielle (ex. plusieurs '
                      'cueillettes de tomates).',
                    ),
                  ),
                  formGap,
                  TextFormField(
                    controller: _notes,
                    decoration: const InputDecoration(
                      labelText: 'Observations',
                    ),
                    maxLines: 3,
                    validator: (v) => Validators.optionalText(
                      v,
                      max: Validators.maxNotesLength,
                    ),
                  ),
                  const SizedBox(height: 24),
                  FilledButton.icon(
                    onPressed: _saving ? null : _save,
                    icon: const Icon(Icons.save),
                    label: Text(
                      _saving ? 'Enregistrement…' : 'Enregistrer la récolte',
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  String _unitName() => _unit == HarvestUnit.autre
      ? (_unitLabel.text.trim().isEmpty ? 'unité' : _unitLabel.text.trim())
      : _unit.symbol;

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final area = Formatters.parseDecimal(_area.text);
    final ok = await runGuarded(context, () async {
      await ref
          .read(harvestRepositoryProvider)
          .record(
            HarvestDraft(
              cropId: widget.crop.id,
              harvestDate: _date,
              quantity: Formatters.parseDecimal(_quantity.text)!,
              unit: _unit,
              unitLabel: _unitLabel.text,
              kgPerUnit: _needsKgPerUnit
                  ? Formatters.parseDecimal(_kgPerUnit.text)
                  : null,
              harvestedArea: area,
              harvestedAreaUnit: area == null ? null : _areaUnit,
              lossesQuantity: Formatters.parseDecimal(_losses.text),
              soldQuantity: Formatters.parseDecimal(_sold.text),
              storedQuantity: Formatters.parseDecimal(_stored.text),
              unitPrice: Formatters.parseDecimal(_price.text),
              isFinal: _isFinal,
              notes: _notes.text,
            ),
            recordSaleAsIncome: _recordSale,
          );
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Récolte enregistrée.');
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) context.pop();
  }
}
