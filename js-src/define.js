if (!window.define) {
    // AMD define
    var ownSrc = document.currentScript.getAttribute('src');
    var srcDir = ownSrc.split('/');
    srcDir.pop();
    srcDir = srcDir.join('/');

    var modules = {};
    var modulePromises = {};

    var loadAsScript = function loadAsScript(id) {
        return new Promise(resolve => {
            var script = document.createElement('script');
            script.src = srcDir + '/' + id + '.js';
            script.dataset.id = id;
            script.onload = resolve;
            document.head.appendChild(script);
        });
    };

    var modLoad = function modLoad(id) {
        if (modulePromises[id]) return modulePromises[id];
        return modulePromises[id] = loadAsScript(id).then(function() {
            if (!modules[id]) throw new Error('Failed to load ' + id + ': no module defined?');
            return modules[id];
        });
    };

    var modLoadAll = function modLoadAll(ids) {
        var result = [];
        for (const id of ids) {
            result.push(modLoad(id));
        }
        return Promise.all(result);
    };

    var require = function require(ids, cb) {
        modLoadAll(ids).then(mods => {
            if (mods.length === 1) cb(mods[0]);
            else cb(mods);
        });
    };

    window.define = function(reqs, run) {
        if (typeof reqs === 'function') {
            run = reqs;
            reqs = [];
        }
        var id = document.currentScript.dataset.id;
        if (!id) {
          id = './' + document.currentScript
              .getAttribute('src')
              .split('/')
              .pop()
              .replace(/\.js$/i, '');

          //console.debug('define() on script with no ID. Inferred: ' + id);
        }
        if (modules[id]) return;
        var exports = {};
        modules[id] = Promise.resolve().then(function() {
            var toLoad = [];
            for (var i = 0; i < reqs.length; i++) {
                var id = reqs[i];
                if (id === 'require') toLoad.push(Promise.resolve(require));
                else if (id === 'exports') toLoad.push(Promise.resolve(exports));
                else toLoad.push(modLoad(id));
            }
            return Promise.all(toLoad);
        }).then(function(loaded) {
            //console.debug('exec module ' + id);
            var result = run.apply(window, loaded);
            if (!exports.default) exports.default = result;
            return exports;
        });
    };
}
