# Simple RSS Editor

## Обязательный параметр
* `url` — URL на обрабатываемый RSS

## Необязательные параметры: возможности и порядок их выполнения

|   | Имя параметра              | Тип каждого из параметров               | Семантика элемента типа  | Действие                                                                                                                                                                                                              |
|---|----------------------------|-----------------------------------------|--------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1 | `amp`                      | наличие/отсутствие                      | делать/не делать         | замена всех вхождений `&amp;` на `&` (для случаев вида `&amp;amp;`, `&amp;quot;` и т.п.)                                                                                                                              |
| 2 | [автоматически всегда]     | —                                       | —                        | замена всех HTML-сущностей на их числовой код, чтобы соответствовать XML, например, `&quot;` заменяется на `&#34;`                                                                                                    |
| 3 | `add_namespace`            | строка                                  | пространство имен        | добавление пространства имен путем простой вставки содержимого `add_namespace` в список атрибутов тега `rss`                                                                                                          |
| 4 | `remove`                   | строка или массив строк                 | имя тега                 | удаление всех тегов с перечисленными именами                                                                                                                                                                          |
| 5 | `rename_from` и `rename_to`| строки или одинаковых длин массивы строк| имя тега до и после      | переименование тега с именем `rename_from` в имя `rename_to`, а в случае массивов аналогичное действие выполняется для каждой пары элементов, сгруппированных по одинаковым индексам                                  |
| 6 | `split`                    | строка или массив строк                 | имя тега                 | разбиение содержимого тега с заданным именем на отдельные слова (предполагая, что изначальное содержимое оформлено в стиле CamelCase) и помещение их  в нижнем регистре в отдельные теги с тем же изначальным именем  |
| 7 | `break`                    | строка или массив строк                 | имя тега                 | вставка `<br/>` внутри каждого тега с заданным именем заместо переносов строк `\n` и `\r` (если идет несколько переносов подряд, то заменяется лишь на одну вставку `<br/>`)                                          |
| 8 | `cdata`                    | строка или массив строк                 | имя тега                 | заворачивание содержимого тегов с заданным именем в структуру `<![CDATA[ ... ]]>`                                                                                                                                     |
| 9 | `replace_from`, `replace_to`| строка или массив строк                | регулярное выражение     | эти и два последующих параметра служат для замен в оборачиваемых тегами текстах. В теге с именем `replace_in` всякое соответствие регулярному выражению `replace_from` (в нотации PCRE, **без** окаймляющих символов `/`) заменяется в соответствии с регулярным выражением  `replace_to`.   Параметры могут использоваться для обычной замены, только любой из символов `\ + * ? [ ^ ] $ ( ) { } = ! < > | : -` должен быть экранирован символов `\` (например, `\(`).|
| 9 | `replace_in`               | строка или массив строк                 | имя тега                 | может принимать специальное значение `*`, что приведёт к поиску и замене соответствий во всех тегах, включая служебные (`pubDate`, `link` и др.).                                                                     |
| 9 | `replace_sens`             | наличие/отсутствие или массив наличий/отсутствий | учитывать регистр или нет | Если для текущей пары регулярных выражений присутствует `replace_sens`, то поиск ведётся с учётом регистра, иначе без учёта.                                                                                |
|10 | `addCategory`              | строка                                  | [XPath-выражение](http://www.w3schools.com/xsl/xpath_intro.asp) | добавление каждому RSS-элементу категорий `category`, содержимое которых определяется XPath-выражением, извлекающее список чего-либо со страницы этого RSS-элемента (адрес берётся из тега `link`, если он есть), впоследствии преобразуемое в список строк. Если XPath-выражение извлекает целые теги, то в итоге категорией будет HTML-код, поэтому XPath-выражение должно извлекать сразу нужную информацию — значение содержимого или атрибута тега |

Пример работы параметра `split`: подано значение `split=tag`, поэтому было `<tag>КрепостьЧПЗдоровьеНебо</tag>`, стало `<tag>крепость</tag><tag>чп</tag><tag>здоровье</tag><tag>небо</tag>`

## Пример использования
В RSS-ленте http://milknews.ru/index/novosti-moloko.rss замена `&amp;` на `&`, добавление пространства имен `xmlns:yandex="http://news.yandex.ru/"`, удаление тега `description`, переименования тега `full-text` (из пространства `yandex`) в `description`, заворачивание в `CDATA` содержимое тегов `title` и `description`, а также добавление `<br/>` заместо переносов строк в содержимом тега `description`, дополнительно к этому проводится замена всех вхождений буквы `ё` на `е` (без учёта регистра) в тегах с именем `description`:
```
index.php?url=http%3A%2F%2Fmilknews.ru%2Findex%2Fnovosti-moloko.rss&amp&add_namespace=xmlns%3Ayandex%3D%22http%3A%2F%2Fnews.yandex.ru%2F%22&remove=description&rename_from=full-text&rename_to=description&cdata[]=title&cdata[]=description&break=description&replace_from=ё&replace_to=е&replace_in=description
```
