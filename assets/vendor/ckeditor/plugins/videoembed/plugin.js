(function() {
    CKEDITOR.plugins.add('videoembed', {
        requires: 'widget',
        init: function(editor) {
            // video widget 정의
            editor.widgets.add('videoembed', {
                // HTML → widget 변환: <video> 태그를 찾아서 widget으로 감쌈
                upcast: function(element) {
                    return element.name === 'video';
                },
                
                // widget 템플릿 (실제 video 태그 유지)
                template: '<video controls playsinline style="max-width:100%;"></video>',
                
                // 에디터에서 widget을 어떻게 표시할지
                editables: {},
                
                // widget 초기화
                init: function() {
                    var el = this.element;
                    // widget wrapper에 리사이즈 가능하도록 설정
                    if (el) {
                        // style 보존
                        var style = el.getAttribute('style') || '';
                        if (style.indexOf('max-width') === -1) {
                            el.setAttribute('style', style + (style ? ';' : '') + 'max-width:100%;');
                        }
                    }
                },
                
                // widget에서 getData() 시 원본 HTML 출력
                downcast: function(element) {
                    return element;
                }
            });
            
            // allowedContent에 video 추가
            editor.addFeature({
                allowedContent: 'video[!src,controls,playsinline,style,width,height,data-*]{*}(*);source[!src,type]{*}(*)'
            });
        }
    });
})();
