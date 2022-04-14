## Current Version
r81
at https://github.com/mrdoob/three.js/releases/tag/r81

## The migration guide for 3dviewer

https://github.com/mrdoob/three.js/wiki/Migration

## Examples

https://github.com/mrdoob/three.js/tree/master/examples/models

## Examples with demo

https://threejs.org/examples/#webgl_loader_collada


## Steps to migrate

Swarm don't have any tests to 3dviewer, which may be implemented in future. For now, you have to grab (if not all) at least
.stl, .dae, .obj files, and try to open them in Safari/IE/Chrome/Firefox/Opera, and visually verify. The current assets
includes(but not limits to):

* monster.dae, [demo](https://threejs.org/examples/#webgl_loader_collada), [digital assets](https://github.com/mrdoob/three.js/tree/master/examples/models/collada/monster)
* male02.obj, [demo](https://threejs.org/examples/#webgl_loader_obj_mtl), [digital assets](https://github.com/mrdoob/three.js/tree/master/examples/obj/male02)
* slotted_disk.stl, [demo](https://threejs.org/examples/#webgl_loader_stl), [digital asset](https://github.com/mrdoob/three.js/tree/master/examples/models/stl)