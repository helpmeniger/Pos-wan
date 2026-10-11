import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../../core/utils/id_generator.dart';

/// Prise ou import de photos, copiées dans le stockage privé de
/// l'application (consultables hors connexion).
///
/// Aucune permission n'est demandée au démarrage : l'appareil photo ou la
/// galerie ne s'ouvrent qu'après un choix explicite de l'utilisateur.
class PhotoService {
  PhotoService({ImagePicker? picker}) : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  Future<String?> pick(ImageSource source) async {
    final file = await _picker.pickImage(
      source: source,
      maxWidth: 1600,
      imageQuality: 80,
    );
    if (file == null) return null;
    final dir = await getApplicationDocumentsDirectory();
    final photos = Directory(p.join(dir.path, 'photos'));
    if (!photos.existsSync()) {
      await photos.create(recursive: true);
    }
    final extension = p.extension(file.path).isEmpty
        ? '.jpg'
        : p.extension(file.path);
    final target = p.join(photos.path, '${IdGenerator.newId()}$extension');
    await File(file.path).copy(target);
    return target;
  }

  /// Demande à l'utilisateur la source de la photo, avec explication.
  Future<String?> pickWithConsent(BuildContext context) async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Ajouter une photo',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              const Text(
                'La photo reste uniquement sur votre téléphone. AgriSaha '
                'n’accède à l’appareil photo ou à la galerie que maintenant, '
                'à votre demande.',
              ),
              const SizedBox(height: 12),
              ListTile(
                leading: const Icon(Icons.photo_camera),
                title: const Text('Prendre une photo'),
                onTap: () => Navigator.pop(context, ImageSource.camera),
              ),
              ListTile(
                leading: const Icon(Icons.photo_library),
                title: const Text('Choisir dans la galerie'),
                onTap: () => Navigator.pop(context, ImageSource.gallery),
              ),
            ],
          ),
        ),
      ),
    );
    if (source == null) return null;
    return pick(source);
  }
}
