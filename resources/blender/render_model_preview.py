"""
Headless Blender poster + optional GLB export for DAM 3D preview pipeline.

Invoked as:
  blender -b --python render_model_preview.py -- <input_abs> <out_png_abs> <size_px> <bg_hex_no_hash> [export_glb_abs]

Exits non-zero on failure; stderr carries a short reason.
"""
from __future__ import annotations

import os
import sys

import bpy


def _die(msg: str, code: int = 1) -> None:
    print(msg, file=sys.stderr)
    sys.exit(code)


def _parse_args() -> dict:
    if "--" not in sys.argv:
        _die("missing -- after --python script.py", 2)
    a = sys.argv[sys.argv.index("--") + 1 :]
    if len(a) < 4:
        _die(
            "need: input_path out_png size_px bg_hex [export_glb_path]",
            2,
        )
    export_glb = ""
    if len(a) >= 5 and str(a[4]).strip():
        export_glb = os.path.abspath(str(a[4]))
    return {
        "in_path": os.path.abspath(str(a[0])),
        "out_png": os.path.abspath(str(a[1])),
        "size": max(32, min(4096, int(a[2]))),
        "bg_hex": str(a[3]).lstrip("#").strip(),
        "export_glb": export_glb,
    }


def _hex_to_rgb(h: str) -> tuple[float, float, float]:
    if len(h) == 6 and all(c in "0123456789abcdefABCDEF" for c in h):
        return (
            int(h[0:2], 16) / 255.0,
            int(h[2:4], 16) / 255.0,
            int(h[4:6], 16) / 255.0,
        )
    return (0.059, 0.090, 0.165)


def _set_render_engine(scene: bpy.types.Scene) -> None:
    for eng in ("BLENDER_EEVEE_NEXT", "BLENDER_EEVEE"):
        try:
            scene.render.engine = eng
            return
        except TypeError:
            continue
    scene.render.engine = "CYCLES"


def _import_model(path: str) -> None:
    ext = os.path.splitext(path)[1].lower()
    if ext == ".glb" or ext == ".gltf":
        bpy.ops.import_scene.gltf(filepath=path)
    elif ext == ".stl":
        bpy.ops.wm.stl_import(filepath=path)
    elif ext == ".obj":
        try:
            bpy.ops.wm.obj_import(filepath=path)
        except AttributeError:
            bpy.ops.import_scene.obj(filepath=path)
    elif ext == ".fbx":
        bpy.ops.import_scene.fbx(filepath=path)
    else:
        _die(f"unsupported extension: {ext}", 3)


def _mesh_objects() -> list[bpy.types.Object]:
    return [o for o in bpy.context.scene.objects if o.type == "MESH"]


def _join_meshes(objs: list[bpy.types.Object]) -> bpy.types.Object:
    if not objs:
        _die("no mesh objects after import", 4)
    bpy.ops.object.select_all(action="DESELECT")
    for o in objs:
        o.select_set(True)
    bpy.context.view_layer.objects.active = objs[0]
    if len(objs) > 1:
        bpy.ops.object.join()
    return bpy.context.view_layer.objects.active


def _normalize_object(obj: bpy.types.Object) -> float:
    bpy.ops.object.select_all(action="DESELECT")
    obj.select_set(True)
    bpy.context.view_layer.objects.active = obj
    bpy.ops.object.origin_set(type="ORIGIN_GEOMETRY", center="BOUNDS")
    bpy.ops.object.location_clear()
    dims = obj.dimensions
    m = max(float(dims.x), float(dims.y), float(dims.z), 1e-8)
    scale = 2.0 / m
    obj.scale = (scale, scale, scale)
    bpy.ops.object.transform_apply(scale=True)
    return max(float(obj.dimensions.x), float(obj.dimensions.y), float(obj.dimensions.z), 1e-8)


def _set_world_color(scene: bpy.types.Scene, rgb: tuple[float, float, float]) -> None:
    world = scene.world
    if world is None:
        world = bpy.data.worlds.new("PreviewWorld")
        scene.world = world
    world.use_nodes = True
    nt = world.node_tree
    for n in list(nt.nodes):
        nt.nodes.remove(n)
    bg = nt.nodes.new("ShaderNodeBackground")
    bg.inputs["Color"].default_value = (*rgb, 1.0)
    bg.inputs["Strength"].default_value = 1.0
    out = nt.nodes.new("ShaderNodeOutputWorld")
    nt.links.new(bg.outputs["Background"], out.inputs["Surface"])


def _setup_camera_light(scene: bpy.types.Scene, target: bpy.types.Object, radius: float) -> None:
    bpy.ops.object.select_all(action="DESELECT")
    dist = max(radius * 2.8, 2.5)
    bpy.ops.object.camera_add(location=(dist * 0.85, -dist * 0.75, dist * 0.55))
    cam = bpy.context.active_object
    cam.name = "Dam3dPreviewCamera"
    c = cam.constraints.new("TRACK_TO")
    c.target = target
    c.track_axis = "TRACK_NEGATIVE_Z"
    c.up_axis = "UP_Y"
    scene.camera = cam

    bpy.ops.object.light_add(type="AREA", location=(dist, dist, dist * 1.1))
    light = bpy.context.active_object
    light.data.energy = 1200.0
    light.data.size = max(radius * 2.0, 3.0)


def main() -> None:
    cfg = _parse_args()
    in_path = cfg["in_path"]
    out_png = cfg["out_png"]
    size = cfg["size"]
    export_glb = cfg["export_glb"]

    if not os.path.isfile(in_path):
        _die(f"input not found: {in_path}", 5)

    ext = os.path.splitext(in_path)[1].lower()
    if ext == ".blend":
        bpy.ops.wm.open_mainfile(filepath=in_path)
    else:
        bpy.ops.wm.read_factory_settings(use_empty=True)
        try:
            _import_model(in_path)
        except Exception as e:  # noqa: BLE001 — surface to stderr for ops
            _die(f"import failed: {e}", 6)

    meshes = _mesh_objects()
    if not meshes:
        _die("no mesh in scene", 7)

    obj = _join_meshes(meshes)
    radius = _normalize_object(obj) * 0.5

    scene = bpy.context.scene
    rgb = _hex_to_rgb(cfg["bg_hex"])
    _set_world_color(scene, rgb)
    _set_render_engine(scene)
    scene.render.film_transparent = False
    scene.render.resolution_x = size
    scene.render.resolution_y = size
    scene.render.resolution_percentage = 100
    scene.render.image_settings.file_format = "PNG"
    scene.render.filepath = out_png

    _setup_camera_light(scene, obj, radius)

    try:
        bpy.ops.render.render(write_still=True)
    except Exception as e:  # noqa: BLE001
        _die(f"render failed: {e}", 8)

    if not os.path.isfile(out_png) or os.path.getsize(out_png) < 32:
        _die("render output missing or too small", 9)

    if export_glb:
        try:
            os.makedirs(os.path.dirname(export_glb), exist_ok=True)
            bpy.ops.export_scene.gltf(
                filepath=export_glb,
                export_format="GLB",
                use_selection=False,
            )
        except Exception as e:  # noqa: BLE001
            _die(f"glb export failed: {e}", 10)
        if not os.path.isfile(export_glb) or os.path.getsize(export_glb) < 64:
            _die("glb export missing or too small", 11)

    print("DAM3D_BLENDER_OK")


if __name__ == "__main__":
    main()
