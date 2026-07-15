# Third-party notices

The generated `kws-runtime.js`, `kws-runtime.wasm`, `kws-api.js`, and the contents packed into `kws-model.data` provide local `HEY_BEAN` and `BEAN` proposals and timestamps. They include or derive from the following projects. Bean's packaged `kws-api.js` wrapper has one local integration patch that preserves an explicit `numTrailingBlanks: 0` setting with nullish/default semantics. The application-authored `wake-worker.js`, `gate-processor.js`, repository training pipeline, and `bean-wake-model-v2.json` are not upstream sherpa-onnx files. The upstream keyword spotter never opens Bean's privacy gate; the Bean-authored three-class model owns acoustic acceptance.

## Apache License 2.0 components

The full license is provided in `licenses/Apache-2.0.txt`.

- [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx), commit `d7526c835a5a70b9a936100dfc39e527a49893b6`.
- `sherpa-onnx-kws-zipformer-zh-en-3M-2025-12-20`, including its ONNX weights and token table, as recorded by `manifest.json`.
- [kaldi-native-fbank](https://github.com/csukuangfj/kaldi-native-fbank), [kaldi-decoder](https://github.com/k2-fsa/kaldi-decoder), [kaldifst](https://github.com/k2-fsa/kaldifst), [OpenFst](https://github.com/csukuangfj/openfst), and [simple-sentencepiece](https://github.com/pkufool/simple-sentencepiece).

OpenFst copyright 2005-2015 Google, Inc. Kaldifst notes that individual authors or their employers own their contributions as discernible from its Git history.

## ONNX Runtime

[ONNX Runtime](https://github.com/microsoft/onnxruntime) 1.17.1 is distributed under the MIT License.

Copyright (c) Microsoft Corporation.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## nlohmann/json

[JSON for Modern C++](https://github.com/nlohmann/json) 3.12.0 is distributed under the MIT License.

Copyright (c) 2013-2025 Niels Lohmann.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## Eigen

[Eigen](https://gitlab.com/libeigen/eigen) 3.4.1 is primarily distributed under Mozilla Public License 2.0. The complete MPL-2.0 text is provided in `licenses/MPL-2.0.txt`. Eigen's source is available at the linked project and from the exact release URL recorded in sherpa-onnx's `cmake/eigen.cmake` at the source commit above.

## hclust-cpp

Copyright © 2011 Daniel Müllner and © 2018 Christoph Dalitz. All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

## KISS FFT

Copyright (c) 2003-2010 Mark Borgerding. All rights reserved. KISS FFT is distributed under the BSD-3-Clause license. The applicable license text is provided in `licenses/BSD-3-Clause.txt`.
