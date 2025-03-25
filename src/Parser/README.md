# GLTFParser
GLTFParser is a standalone glTF v2.0 file parser library written for the GLTFHandler extension.

## Features
- Written in native PHP using standard libraries.
- Reads both .gltf and .glb file formats.
- Supports parser flags to restrict local URI resolution and separately remote URI resolution. <small>[see [parse and validate](#Parse-and-validate) for usage]</small>
- Export feature to convert GLTF and GLB files into an embedded portable GLB, resolving all URI references.
- Utility functions for rendering and setting upload restrictions:
  <br/><small>[see [usage](#Usage) for example code and sample outputs]</small>
  - `GLTFParser::computeModelDimensions()`: Returns an array of 3 float values `[x, y, z]` specifying the dimensions of
    the model.
  - `GLTFParser::computeStats()`: Returns statistics about the model (pertains to rendering).

## Usage
### Parse and validate
GLTFParser currently only supports files in the local filesystem. Local URIs are resolved relative to the directory of
the glTF file (this behaviour is based as per [glTF 2.0 spec](https://registry.khronos.org/glTF/specs/2.0/glTF-2.0.html#_buffer_uri)).
The  example below parses [AnisotropyBarnLamp.glb](https://github.com/KhronosGroup/glTF-Sample-Assets/blob/1d4b083ca989bbf594309cb2ea66b4dc89a84783/Models/AnisotropyBarnLamp/glTF-Binary/AnisotropyBarnLamp.glb)
and prints a basic metadata report.
```php
try{
    $parser = new GLTFParser("AnisotropyBarnLamp.glb");
}catch(InvalidArgumentException $e){
    // parsing or validation failed, read $e->getMessage(), $e->getCode() for elaboration
    return;
}
echo "Format: glTF ", $parser->version, "\n";
echo "Generator: ", $parser->generator ?? "unknown", "\n";
echo "Copyright: ", $parser->copyright ?? "unknown", "\n";
```
```yaml
Format: glTF 2
Generator: 3ds Max, Max2Babylon, Visual Studio Code, glTF Tools
Copyright: (c) 2023 Wayfair, model and textures by Eric Chadwick, CC BY 4.0.
```
The parser does not resolve URI resources by default. The library provides two parser flags `FLAG_RESOLVE_LOCAL_URI` and
`FLAG_RESOLVE_REMOTE_URI` to access local (e.g., `images/image.png`) and remote URIs (e.g., `https://example.com/image.png`)
respectively. The example below parses [StainedGlassLamp.gltf](https://github.com/KhronosGroup/glTF-Sample-Assets/blob/5bad5aaa0bbb5d0f9cdc934e626f27d0df1e79b8/Models/StainedGlassLamp/glTF/StainedGlassLamp.gltf)
which defines multiple local URI resources to access buffers and images from (Ctrl+F for "uri" in the file).
```php
// git clone https://github.com/KhronosGroup/glTF-Sample-Assets.git
$path = "glTF-Sample-Assets/Models/StainedGlassLamp/glTF/StainedGlassLamp.gltf";
$parser = new GLTFParser($path, GLTFParser::FLAG_RESOLVE_LOCAL_URI);
```

### Model statistics
`GLTFParser::computeModelDimensions()` computes the bounding dimensions of the model by obtaining a bounding box and
calculating the length of each of the 3 axes. A bounding box is a cuboid that fully contains the model. glTF rendering
libraries like [model-viewer](https://github.com/google/model-viewer) default to a size of 300x150px canvas, but
alternatively let users specify sizing.
```php
$bounds = $parser->computeModelDimensions();  // [x, y, z]
echo json_encode($bounds); // [0.1908092126250267,0.25523873791098595,0.22654122821086276]
```

`GLTFParser::computeStats()` computes and returns statistics of the model as reported by
[KhronosGroup/glTF-Validator](https://github.com/KhronosGroup/glTF-Validator) report generation tool. These properties
normally conform to rendering (i.e., client-side constraints) rather than server-side processing requirements (note that
GLTFParser is not a renderer). These properties can still be necessary to lay restrictions on hardware-intensive models.
```php
$stats = $parser->computeStats();
echo json_encode($stats, JSON_PRETTY_PRINT);
```
```json
{
    "animationCount": 0,
    "drawCallCount": 3,
    "materialCount": 3,
    "totalTriangleCount": 10203,
    "totalVertexCount": 7712
}
```

### Export as embedded binary file
GLTFParser can transform GLTF and GLB files into a portable GLB file. GLTF and GLB files may define local and remote
URIs. A user (or a host) must therefore provide external dependent files in addition, making the model definition less
portable. Embedding resolves all URIs and includes their data within the output GLB file. For media platforms, this is
a necessary security mechanism to ensure users do not request unvetted hosts for resources.
```php
// git clone https://github.com/KhronosGroup/glTF-Sample-Assets.git
$path = "glTF-Sample-Assets/Models/Duck/glTF/Duck.gltf";
$parser = new GLTFParser($path, GLTFParser::FLAG_RESOLVE_LOCAL_URI | GLTFParser::FLAG_RESOLVE_REMOTE_URI);
$contents = $parser->exportEmbeddedBinary();
file_put_contents("Duck.glb", $contents);
```

## Limitations
- No support for glTF 1.0. GLTFParser only works with glTF 2.0 files.
- No glTF extension support. Extensions like [`KHR_draco_mesh_compression`](https://github.com/KhronosGroup/glTF/blob/main/extensions/2.0/Khronos/KHR_draco_mesh_compression/README.md) are common and need to be supported.
  At the moment, model statistics cannot be properly computed for files using mesh extensions.
- No way to control behavior of local URI resolver. Webservers may not store the glTF file in the same directory as the
  local URI resources that it references.
- No way to allow certain remote URIs while disallowing others. GLTFParser will either allow all remote URIs or none
  (default: none).
