let congressInstanceIdPicker, initGkPagesAddons, initGkEditorPage;

$(document).ready(() => {
    if (!window.fetch) return; // we use fetch
    for (const node of document.querySelectorAll('.akso-field-congress-instance-id')) {
        congressInstanceIdPicker(node);
    }

    initGkPagesAddons();
    initGkEditorPage();
});

{
    // congress instance id picker
    const knownCongresses = {};

    const LIMIT = 10;
    const fetch2Json = res => res.json();
    const fetchCongresses = (offset) => {
        return fetch(`/admin/akso_bridge?task=list_congresses&offset=${offset}&limit=${LIMIT}`);
    };
    const fetchInstances = (congress, offset) => {
        return fetch(`/admin/akso_bridge?task=list_congress_instances&congress=${congress}&offset=${offset}&limit=${LIMIT}`);
    };
    const fetchCongressInstance = (congress, instance) => {
        return fetch(`/admin/akso_bridge?task=name_congress_instance&congress=${congress}&instance=${instance}`);
    };

    const loadCongresses = (offset) => fetchCongresses(offset).then(fetch2Json).then(res => {
        if (res.error) throw res.error;
        const ids = [];
        for (const item of res.result) {
            ids.push(item.id);
            if (!knownCongresses[item.id]) knownCongresses[item.id] = {};
            Object.assign(knownCongresses[item.id], item);
            if (!knownCongresses[item.id].instances) knownCongresses[item.id].instances = {};
        }
        return ids;
    });

    const loadInstances = (congress, offset) => fetchInstances(congress, offset).then(fetch2Json).then(res => {
        if (res.error) throw res.error;
        if (!knownCongresses[congress]) knownCongresses[congress] = { instances: {} };
        const knownInstances = knownCongresses[congress].instances;
        const ids = [];
        for (const item of res.result) {
            ids.push(item.id);
            if (!knownInstances[item.id]) knownInstances[item.id] = {};
            Object.assign(knownInstances[item.id], item);
        }
        return ids;
    });

    const loadCongressInstance = (congress, instance) => fetchCongressInstance(congress, instance).then(fetch2Json).then(res => {
        if (res.error) throw res.error;
        if (!knownCongresses[congress]) knownCongresses[congress] = { instances: {} };
        const knownInstances = knownCongresses[congress].instances;
        if (!knownInstances[instance]) knownInstances[instance] = {};
        knownCongresses[congress].name = res.result.congress;
        knownInstances[instance].name = res.result.instance;
    });

    const LOADING = 'Ŝarĝas…';
    const PICK_ONE = 'Elekti kongreson';
    const LOAD_MORE = 'Montri pliajn';

    const getCongress = congress => knownCongresses[congress] || null;
    const getInstance = (congress, instance) => {
        if (knownCongresses[congress]) return knownCongresses[congress].instances[instance] || null;
        else return null;
    };

    congressInstanceIdPicker = (node) => {
        const fieldInput = node.querySelector('.akso-field-input');
        fieldInput.style.display = 'none';
        for (const n of node.querySelectorAll('.akso-field-noscript')) n.parentNode.removeChild(n);

        const previewButton = document.createElement('button');
        previewButton.classList.add('button');
        previewButton.textContent = LOADING;
        node.appendChild(previewButton);

        const picker = document.createElement('div');
        picker.className = 'akso-picker-list hidden';
        node.appendChild(picker);

        const pickerHeader = document.createElement('div');
        pickerHeader.className = 'akso-picker-header';
        pickerHeader.textContent = PICK_ONE;
        picker.appendChild(pickerHeader);
        const pickerItems = document.createElement('ul');
        pickerItems.className = 'akso-picker-list-items';
        picker.appendChild(pickerItems);
        const pickerLoadMore = document.createElement('button');
        pickerLoadMore.textContent = LOAD_MORE;
        pickerLoadMore.className = 'akso-picker-list-load-more';
        picker.appendChild(pickerLoadMore);

        let renderState;
        let open = false;
        let previewError = null;
        let loadingPreview = null;

        const loadPreview = (congress, instance) => {
            if (loadingPreview || previewError) return;
            previewError = null;
            loadingPreview = loadCongressInstance(congress, instance).catch(err => {
                previewError = err;
            }).then(() => {
                loadingPreview = null;
                renderState();
            });
        };

        let pickerCongress = null;
        let loadedItems = 0;
        let loadingItems = null;
        let pickerError = null;
        const clearPicker = () => {
            pickerLoadMore.classList.remove('hidden');
            loadedItems = 0;
            pickerItems.innerHTML = '';
        };
        const loadPickerItems = () => {
            if (loadingItems) return;
            pickerError = null;
            pickerLoadMore.textContent = LOADING;
            const hasCongress = pickerCongress !== null;

            pickerHeader.innerHTML = '';
            if (hasCongress) {
                pickerHeader.textContent = getCongress(pickerCongress).name;
            } else {
                pickerHeader.textContent = PICK_ONE;
            }

            const backButton = document.createElement('button');
            const icon = document.createElement('i');
            if (hasCongress) {
                icon.className = 'fa fa-reply';
            } else {
                icon.className = 'fa fa-close';
            }
            backButton.appendChild(icon);
            backButton.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                if (hasCongress) {
                    pickerCongress = null;
                    clearPicker();
                    loadPickerItems();
                } else {
                    open = false;
                    renderState();
                }
            });
            pickerHeader.insertBefore(backButton, pickerHeader.firstChild)

            if (hasCongress) {
                loadingItems = loadInstances(pickerCongress, loadedItems);
            } else {
                loadingItems = loadCongresses(loadedItems);
            }
            loadingItems.then(items => {
                if (!items.length) {
                    pickerLoadMore.classList.add('hidden');
                }

                loadedItems += items.length;
                if (!open) return;
                for (const id of items) {
                    const item = hasCongress ? getInstance(pickerCongress, id) : getCongress(id);
                    const itemNode = document.createElement('li');
                    itemNode.className = 'picker-list-item';

                    itemNode.textContent = item.name;
                    if (!hasCongress) {
                        // this is a congress item
                        const badge = document.createElement('span');
                        badge.className = 'akso-org-badge';
                        badge.textContent = item.org.toUpperCase();
                        itemNode.insertBefore(badge, itemNode.firstChild)
                    }

                    itemNode.addEventListener('click', e => {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!hasCongress) {
                            pickerCongress = id;
                            clearPicker();
                            loadPickerItems();
                        } else {
                            open = false;
                            fieldInput.value = `${pickerCongress}/${id}`;
                            renderState();
                        }
                    });
                    pickerItems.appendChild(itemNode);
                }
            }).catch(err => {
                pickerError = err;
            }).then(() => {
                pickerLoadMore.textContent = LOAD_MORE;
                loadingItems = null;
                renderState();
            });
        };
        const openedPicker = () => {
            if (!loadedItems && !pickerError) {
                loadPickerItems();
            }
        };

        renderState = () => {
            let value = null;
            if (fieldInput.value) {
                const parts = fieldInput.value.split('/');
                value = [parseInt(parts[0], 10), parseInt(parts[1], 10)];
            }

            let res;
            if (open) {
                previewButton.classList.add('hidden');
                picker.classList.remove('hidden');
                openedPicker();
            } else {
                previewButton.classList.remove('hidden');
                picker.classList.add('hidden');
                clearPicker();
                previewButton.innerHTML = '';

                if (value) {
                    const congress = getCongress(value[0]);
                    const instance = getInstance(value[0], value[1]);

                    if (congress && instance) {
                        previewButton.textContent = `${congress.name} — ${instance.name}`;
                    } else {
                        previewButton.textContent = LOADING;
                        loadPreview(value[0], value[1]);
                    }
                    const spacer = document.createElement('span');
                    spacer.textContent = '\u00a0';
                    previewButton.insertBefore(spacer, previewButton.firstChild);
                    const icon = document.createElement('i');
                    icon.className = 'fa fa-edit';
                    previewButton.insertBefore(icon, previewButton.firstChild);
                } else {
                    previewButton.textContent = PICK_ONE;
                }
            }
        };

        renderState();

        previewButton.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            pickerCongress = null;
            open = true;
            renderState();
        });

        pickerLoadMore.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            loadPickerItems();
            renderState();
        });
    };
}

{
    const locale = window.aksoAdminLocale.gk;
    const GK_TEMPLATE = 'blog_item';

    initGkPagesAddons = () => {
        // stuff in /admin/pages
        if (location.pathname.toLowerCase() !== '/admin/pages') return;

        const titlebarButtonGroup = document.createElement('div');
        titlebarButtonGroup.className = 'button-group';
        titlebarButtonGroup.innerHTML = `
        <button type="button" class="button">
            ${locale.createGkPage}
        </button>
        `;
        const titlebarAdd = document.querySelector('#titlebar-add');
        titlebarAdd.parentNode.insertBefore(titlebarButtonGroup, titlebarAdd);
        titlebarAdd.parentNode.insertBefore(document.createTextNode(' '), titlebarAdd);

        const newGkPageDialog = document.createElement('div');
        newGkPageDialog.className = 'new-gk-page';
        newGkPageDialog.innerHTML = document.querySelector('#new-page').outerHTML;
        newGkPageDialog.querySelector('#new-page').id = 'new-gk-page';
        const remodal = $(newGkPageDialog).remodal();

        titlebarButtonGroup.querySelector('.button').addEventListener('click', () => {
            remodal.open();
        });

        {
            const newPageForm = newGkPageDialog.querySelector('#new-gk-page');
            // remove default controls
            $(newPageForm.querySelector('input[name="data[title]"]')).parents('.block-text').remove();
            $(newPageForm.querySelector('input[name="data[folder]"]')).parents('.block-text').remove();
            $(newPageForm.querySelector('select[name="data[name]"]')).parents('.block-select').remove();

            newPageForm.querySelector('h1').textContent = locale.setup.title;

            // add invisible inputs
            const pageTitleInput = document.createElement('input');
            pageTitleInput.type = 'hidden';
            pageTitleInput.required = true;
            pageTitleInput.name = 'data[title]';
            newPageForm.appendChild(pageTitleInput);
            const folderInput = document.createElement('input');
            folderInput.type = 'hidden';
            folderInput.required = true;
            folderInput.name = 'data[folder]';
            newPageForm.appendChild(folderInput);
            $(newPageForm).append(`<input type="hidden" name="data[name]" value="${GK_TEMPLATE}" />`);

            // add user controls
            const makeBlock = (label) => {
                const block = document.createElement('div');
                block.className = 'block block-text';
                block.innerHTML = `
                <div class="form-field grid">
                    <div class="form-label block size-1-3"><label></label></div>
                    <div class="form-data block size-2-3" data-grav-field="text">
                        <div class="form-input-warpper">
                            <input type="text" required />
                        </div>
                    </div>
                </div>
                `;
                block.querySelector('label').textContent = label;
                newPageForm.querySelector('.block-section').parentNode.insertBefore(
                    block,
                    newPageForm.querySelector('.block-section').nextElementSibling,
                );
                return block.querySelector('input');
            };
            const gkNum = makeBlock(locale.setup.gkNum);
            gkNum.type = 'number';
            const gkTitle = makeBlock(locale.setup.gkTitle);

            const update = () => {
                folderInput.value = gkNum.value;
                pageTitleInput.value = locale.gkTitleFmt(gkNum.value, gkTitle.value);
            };

            gkNum.addEventListener('change', update);
            gkTitle.addEventListener('change', update);

            newPageForm.dataset.aksoGkPageAddons = 'true';
        }
    };

    initGkEditorPage = () => {
        const form = document.querySelector('#blueprints');

        const gkNum = document.querySelector('input[name="data[header][gk_num]"]');
        const gkTitle = document.querySelector('input[name="data[header][gk_title]"]');
        if (!gkNum) return;

        const gravTitle = document.querySelector('input[name="data[header][title]"]');
        {
            // hide title parent node
            const gravTitleField = gravTitle.parentNode.parentNode.parentNode;
            gravTitleField.style.display = 'none';

            // ..and replace with gkNum/gkTitle
            const gkNumField = gkNum.parentNode.parentNode.parentNode;
            gkNumField.parentNode.removeChild(gkNumField);
            const gkTitleField = gkTitle.parentNode.parentNode.parentNode;
            gkTitleField.parentNode.removeChild(gkTitleField);
            gravTitleField.parentNode.insertBefore(gkTitleField, gravTitleField);
            gravTitleField.parentNode.insertBefore(gkNumField, gkTitleField);
            gkTitleField.classList.add('vertical');
            gkNumField.classList.add('vertical');
        }

        if (!gkNum.value && !gkTitle.value && gravTitle.value) {
            // try to initialize fields from the title
            const titleParts = gravTitle.value.match(locale.gkTitleRe);
            if (titleParts) {
                gkNum.value = titleParts.groups.num;
                gkTitle.value = titleParts.groups.title.trim();
            }
        }

        const makeAlias = (index) => {
            const alias = document.createElement('input');
            alias.type = 'hidden';
            alias.name = `data[header][routes][aliases][${index}]`;
            form.appendChild(alias);
            return alias;
        };
        const aliases = [];

        const updateTitle = () => {
            gravTitle.value = locale.gkTitleFmt(gkNum.value, gkTitle.value);

            const aliasValues = locale.aliases(gkNum.value);
            for (let i = 0; i < aliasValues.length; i++) {
                if (!aliases[i]) aliases[i] = makeAlias(i);
                aliases[i].value = aliasValues[i];
            }
        };

        console.log('Init GK editor page');
        gkNum.addEventListener('change', updateTitle);
        gkTitle.addEventListener('change', updateTitle);

        updateTitle();

        {
            const sentToSubscribers = document.querySelector('.block-akso_gk_sent_to_subscribers');
            const checkbox = sentToSubscribers.querySelector('input');
            checkbox.style.display = 'none';
            const didSendToSubs = checkbox.checked;

            const sendToSubs = document.createElement('input');
            sendToSubs.type = 'checkbox';
            sendToSubs.name = 'akso_gk_send_to_subs';
            sendToSubs.style.display = 'none';
            sentToSubscribers.appendChild(sendToSubs);

            const sendButton = document.createElement('button');
            sendButton.className = 'button';
            sendButton.type = 'button';
            checkbox.parentNode.appendChild(sendButton);

            const update = () => {
                sendToSubs.classList.remove('secondary');
                if (didSendToSubs) {
                    sendButton.textContent = checkbox.checked
                        ? locale.sendToSubs.didSend
                        : locale.sendToSubs.willUnset;
                    sendToSubs.classList.add('secondary');
                    if (checkbox.checked) {
                        const icon = document.createElement('i');
                        icon.className = 'fa fa-check';
                        sendButton.insertBefore(document.createTextNode(' '), sendButton.firstChild);
                        sendButton.insertBefore(icon, sendButton.firstChild);
                    }
                } else {
                    sendButton.textContent = checkbox.checked
                        ? locale.sendToSubs.willSend
                        : locale.sendToSubs.send;
                    if (checkbox.checked) {
                        const icon = document.createElement('i');
                        icon.className = 'fa fa-send';
                        sendButton.insertBefore(document.createTextNode(' '), sendButton.firstChild);
                        sendButton.insertBefore(icon, sendButton.firstChild);
                    }
                }
                sendToSubs.checked = checkbox.checked && !didSendToSubs;
            };
            update();

            checkbox.addEventListener('change', () => {
                update();
            });

            sendButton.addEventListener('click', () => {
                checkbox.checked = !checkbox.checked;
                update();
            });
        }
    };
}
